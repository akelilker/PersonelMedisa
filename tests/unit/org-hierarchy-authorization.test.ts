import { describe, expect, it } from "vitest";
import { readFileSync, readdirSync } from "node:fs";
import { resolve } from "node:path";
import { spawnSync } from "node:child_process";
import {
  ALL_ROLES,
  ASSIGNABLE_USER_ROLES,
  BIRIM_ASSIGNMENT_ROLES,
  BOLUM_ASSIGNMENT_ROLES,
  GLOBAL_SCOPE_ROLES,
  SUBE_ASSIGNMENT_ROLES,
  TECHNICAL_ROLES,
  type UserRole
} from "../../src/types/auth";
import {
  getRolePermissions,
  hasRolePermission,
  sessionAllowsSubeAccess
} from "../../src/lib/authorization/role-permissions";
import type { AuthSession } from "../../src/types/auth";

const root = process.cwd();
const PHP_ORG = resolve(root, "api/src/Scope/OrgScope.php");
const PHP_ROLES = resolve(root, "api/src/Auth/RolePermissions.php");
const MIG_071 = resolve(root, "api/migrations/071_org_hierarchy_authorization.sql");

const HUMAN_9: UserRole[] = [
  "PERSONEL",
  "MUHASEBE",
  "IK_SORUMLUSU",
  "IK_PERSONELI",
  "BIRIM_AMIRI",
  "BOLUM_YONETICISI",
  "SUBE_YONETICISI",
  "GENEL_YONETICI",
  "SISTEM_YONETICISI"
];

function sessionFor(role: UserRole, subeIds: number[] = []): AuthSession {
  return {
    token: "t",
    user: { id: 1, ad_soyad: "T", rol: role, sube_ids: subeIds },
    ui_profile: "yonetim",
    active_sube_id: subeIds.length === 1 ? subeIds[0]! : null
  };
}

describe("org hierarchy authorization contract", () => {
  it("locks exact 9 human + 1 technical catalog including SUBE_YONETICISI", () => {
    expect([...ASSIGNABLE_USER_ROLES].sort()).toEqual([...HUMAN_9].sort());
    expect(ASSIGNABLE_USER_ROLES).toHaveLength(9);
    expect(ASSIGNABLE_USER_ROLES).toContain("SUBE_YONETICISI");
    expect(TECHNICAL_ROLES).toEqual(["AUTH_SMOKE_READONLY"]);
    expect([...ALL_ROLES].sort()).toEqual([...HUMAN_9, "AUTH_SMOKE_READONLY"].sort());
  });

  it("keeps SUBE / BOLUM / BIRIM assignment roles independent", () => {
    expect(SUBE_ASSIGNMENT_ROLES).toContain("SUBE_YONETICISI");
    expect(BOLUM_ASSIGNMENT_ROLES).toEqual(["BOLUM_YONETICISI"]);
    expect(BIRIM_ASSIGNMENT_ROLES).toEqual(["BIRIM_AMIRI"]);
    expect(GLOBAL_SCOPE_ROLES).toEqual(["GENEL_YONETICI", "SISTEM_YONETICISI"]);
    expect(SUBE_ASSIGNMENT_ROLES).not.toContain("BOLUM_YONETICISI");
    expect(BOLUM_ASSIGNMENT_ROLES).not.toContain("SUBE_YONETICISI");
  });

  it("FE/BE permission parity includes SUBE_YONETICISI", () => {
    const php = readFileSync(PHP_ROLES, "utf8");
    expect(php).toContain("'SUBE_YONETICISI'");
    expect(hasRolePermission("SUBE_YONETICISI", "personeller.view")).toBe(true);
    expect(hasRolePermission("SUBE_YONETICISI", "yonetim-paneli.manage")).toBe(false);
    expect(getRolePermissions("SUBE_YONETICISI").length).toBeGreaterThan(10);
  });

  it("sessionAllowsSubeAccess fail-closes non-global empty branch scope", () => {
    expect(sessionAllowsSubeAccess(sessionFor("GENEL_YONETICI", []), 9)).toBe(true);
    expect(sessionAllowsSubeAccess(sessionFor("SISTEM_YONETICISI", []), 9)).toBe(true);
    expect(sessionAllowsSubeAccess(sessionFor("SUBE_YONETICISI", []), 1)).toBe(false);
    // İK reach is role-derived, so an empty branch grant is a valid fully
    // readable state rather than a fail-closed one.
    expect(sessionAllowsSubeAccess(sessionFor("IK_SORUMLUSU", []), 1)).toBe(true);
    expect(sessionAllowsSubeAccess(sessionFor("IK_PERSONELI", []), 1)).toBe(true);
    expect(sessionAllowsSubeAccess(sessionFor("MUHASEBE", []), 1)).toBe(false);
    expect(sessionAllowsSubeAccess(sessionFor("SUBE_YONETICISI", [1, 2]), 1)).toBe(true);
    expect(sessionAllowsSubeAccess(sessionFor("SUBE_YONETICISI", [1, 2]), 3)).toBe(false);
  });

  it("migration 071 adds role + assignment tables without data remaps", () => {
    const sql = readFileSync(MIG_071, "utf8");
    expect(sql).toContain("SUBE_YONETICISI");
    expect(sql).toContain("CREATE TABLE IF NOT EXISTS user_bolumler");
    expect(sql).toContain("CREATE TABLE IF NOT EXISTS user_birimler");
    expect(sql).not.toMatch(/UPDATE\s+users\s+SET\s+rol/i);
    expect(sql).not.toMatch(/INSERT\s+INTO\s+user_bolumler/i);
    expect(sql).not.toMatch(/INSERT\s+INTO\s+user_birimler/i);
    const migrations = readdirSync(resolve(root, "api/migrations"));
    expect(migrations).toContain("071_org_hierarchy_authorization.sql");
  });

  it("OrgScope owns fail-closed empty assignment + personel org filter", () => {
    const php = readFileSync(PHP_ORG, "utf8");
    expect(php).toContain("assertRequiredAssignment");
    expect(php).toContain("appendPersonelOrgFilter");
    expect(php).toContain("Bolum kapsami atanmamis");
    expect(php).toContain("Birim kapsami atanmamis");
    expect(php).toContain("Sube kapsami atanmamis");
    expect(php).toContain("GLOBAL_ROLES");
    expect(php).not.toContain("usesLegacySubeFallback");
  });

  it("BOLUM/BIRIM require canonical unit assignment; user_subeler is not a fallback", () => {
    const php = readFileSync(PHP_ORG, "utf8");
    expect(php).not.toContain("usesLegacySubeFallback");
    expect(php).not.toMatch(/STAGE A compatibility/);
    expect(php).toContain("user_subeler is not a fallback");
    expect(php).toContain("if (count(self::allowedBolumIds($user)) === 0) {");
    expect(php).toContain("if (count(self::allowedBirimIds($user)) === 0) {");
    expect(php).not.toMatch(
      /allowedBolumIds\(\$user\)\) === 0 && count\(self::allowedSubeIds\(\$user\)\) === 0/,
    );
    expect(php).not.toMatch(
      /allowedBirimIds\(\$user\)\) === 0 && count\(self::allowedSubeIds\(\$user\)\) === 0/,
    );
    // Optional formal SGK user_subeler must not confine BOLUM org access.
    expect(php).toMatch(
      /function assertActiveSubeNarrow[\s\S]*BOLUM_ASSIGNMENT_ROLES[\s\S]*return;[\s\S]*allowedSubeIds/,
    );
    const yonetim = readFileSync(resolve(root, "api/src/Controllers/YonetimController.php"), "utf8");
    expect(yonetim).toContain("BOLUM_YONETICISI icin en az bir bolum atamasi zorunludur.");
    expect(yonetim).toContain("BIRIM_AMIRI icin en az bir birim atamasi zorunludur.");
    expect(yonetim).not.toContain("bolum veya sube atamasi");
    expect(yonetim).not.toContain("birim veya sube atamasi");
    expect(yonetim).not.toContain("STAGE A: unit roles may still use legacy branch scope");
    const schema = readFileSync(
      resolve(root, "api/src/Database/UserOrgAssignmentSchema.php"),
      "utf8",
    );
    expect(schema).toContain("SHOW TABLES LIKE 'user_bolumler'");
    expect(schema).toContain("SHOW TABLES LIKE 'user_birimler'");
    expect(schema).toContain("isSubeYoneticisiRoleReady");
  });

  it("ManagerApprovalScope owns BA picker/chain; approval controllers drop user_subeler BA auth", () => {
    const owner = readFileSync(resolve(root, "api/src/Scope/ManagerApprovalScope.php"), "utf8");
    expect(owner).toContain("user_birimler");
    expect(owner).toContain("listBirimAmiriOptionsForSube");
    expect(owner).toContain("enrichReportFiltersForActor");
    expect(owner).not.toMatch(/INNER\s+JOIN\s+user_subeler/i);

    const controllers = [
      "BildirimPuantajEtkiAdaylariController.php",
      "GenelYoneticiBildirimOnaylariController.php",
      "HaftalikBildirimMutabakatlariController.php",
      "AylikBildirimOnaylariController.php",
      "BildirimlerController.php"
    ];
    for (const file of controllers) {
      const src = readFileSync(resolve(root, `api/src/Controllers/${file}`), "utf8");
      expect(src).not.toMatch(/INNER\s+JOIN\s+user_subeler/i);
      expect(src).not.toContain("function assertAmirScope");
      expect(src).toContain("ManagerApprovalScope");
    }

    const rapor = readFileSync(
      resolve(root, "api/src/Services/BildirimPuantajEtkiRaporQueryService.php"),
      "utf8"
    );
    expect(rapor).toContain("PersonelOrgStructureSchema::hasPersonelScopeColumns");
    expect(rapor).toContain("p.bolum_id IN");
    expect(rapor).toContain("p.birim_id IN");
    expect(rapor).toContain("bolum_ids");
  });

  it("ManagerApprovalScope PHP matrix PASS (O–T)", () => {
    const result = spawnSync(
      "php",
      [resolve(root, "tests/php/ManagerApprovalScopePhpTestRunner.php")],
      { encoding: "utf8" }
    );
    if (result.status !== 0) {
      throw new Error(
        `ManagerApprovalScope PHP runner failed (status=${result.status}):\n${result.stdout}\n${result.stderr}`
      );
    }
    expect(result.stdout).toContain("MANAGER_APPROVAL_SCOPE_MATRIX=PASS");
  });
});
