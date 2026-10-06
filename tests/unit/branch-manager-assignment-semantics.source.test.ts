import { describe, expect, it } from "vitest";
import { readdirSync, readFileSync } from "node:fs";
import { resolve } from "node:path";
import { spawnSync } from "node:child_process";

const root = process.cwd();

function read(rel: string): string {
  return readFileSync(resolve(root, rel), "utf8");
}

describe("branch manager assignment semantics (088)", () => {
  it("adds dedicated sube_sorumlu_yoneticiler owner separate from user_subeler", () => {
    const migrations = readdirSync(resolve(root, "api/migrations"))
      .filter((f) => /^\d+_/.test(f))
      .sort();
    expect(migrations.at(-1)).toBe("097_qr_attendance_location_audit.sql");

    const sql = read("api/migrations/088_sube_sorumlu_yoneticiler.sql");
    expect(sql).toContain("CREATE TABLE IF NOT EXISTS sube_sorumlu_yoneticiler");
    expect(sql).not.toMatch(/INSERT\s+INTO\s+sube_sorumlu_yoneticiler/i);
    expect(sql).not.toMatch(/UPDATE\s+personeller/i);
    expect(sql).not.toMatch(/INSERT\s+INTO\s+user_subeler/i);

    const schema = read("api/src/Services/Organizasyon/SubeSorumluYoneticiSchema.php");
    expect(schema).toContain("Durable branch-manager responsibility assignment owner");
    expect(schema).toContain("user_subeler remains the shared branch access");
    expect(schema).toContain("function replaceForSube");
    expect(schema).toContain("ELIGIBLE_ROLES");

    const org = read("api/src/Services/Organizasyon/OrganizasyonService.php");
    expect(org).toContain("sorumlu_yonetici_user_ids");
    expect(org).toContain("SubeSorumluYoneticiSchema::replaceForSube");
    expect(org).not.toMatch(
      /SubeSorumluYoneticiSchema::replaceForSube[\s\S]{0,200}replaceUserSubeler/
    );

    const scope = read("api/src/Scope/OrgScope.php");
    expect(scope).toContain("SUBE_ASSIGNMENT_ROLES");
    expect(scope).not.toContain("sube_sorumlu_yoneticiler");

    const ui = read("src/features/yonetim/pages/YonetimPaneliPage.tsx");
    expect(ui).toContain("Sorumlu Yönetici(ler)");
    expect(ui).toContain("yonetim-sube-sorumlu-yonetici");
    expect(ui).toContain("Şube Yetkisi");
  });

  it("documents ACCESS_SCOPE_ONLY for user_subeler and TECHNICAL_GAP closure via 088", () => {
    const doc = read("docs/guncel/142-branch-manager-assignment-semantics.md");
    expect(doc).toContain("USER_SUBELER_SEMANTIC = ACCESS_SCOPE_ONLY");
    expect(doc).toContain("BRANCH_MANAGER_TECHNICAL_STATUS = TECHNICAL_GAP_LOCAL_FIXABLE");
    expect(doc).toContain("CANONICAL_OWNER_AFTER = sube_sorumlu_yoneticiler");
    expect(doc).toContain("A1_SCOPE_MODEL_UNCHANGED");
    expect(doc).toContain("PRIMARY_ROLE_CHANGE_REQUIRED = NO");
    expect(doc).toContain("MULTI_ROLE_REQUIRED = NO");
  });

  it("runs focused mysql semantics when disposable DSN is present", () => {
    const result = spawnSync("php", ["tests/php/SubeSorumluYoneticiMysqlTestRunner.php"], {
      cwd: root,
      encoding: "utf8",
      env: process.env,
      shell: false
    });
    expect(result.status, result.stderr || result.stdout).toBe(0);
    expect(result.stdout).toMatch(/SKIP:|verify-sube-sorumlu-yonetici-mysql: OK/);
    if (result.stdout.includes("verify-sube-sorumlu-yonetici-mysql: OK")) {
      expect(result.stdout).toContain("[PASS] A home branch unchanged");
      expect(result.stdout).toContain("[PASS] B physical location unchanged");
      expect(result.stdout).toContain("[PASS] C one person manages multiple branches");
      expect(result.stdout).toContain("[PASS] D zero-manager branch valid");
      expect(result.stdout).toContain("[PASS] E removal no personnel transfer");
      expect(result.stdout).toContain("[PASS] F user_subeler access scope unchanged");
      expect(result.stdout).toContain("[PASS] G primary role unchanged");
    }
  });
});
