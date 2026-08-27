import { describe, expect, it } from "vitest";
import { readFileSync } from "node:fs";
import { resolve } from "node:path";
import { spawnSync } from "node:child_process";

const root = process.cwd();

function read(rel: string): string {
  return readFileSync(resolve(root, rel), "utf8");
}

describe("org scope schema hardening", () => {
  it("Login + bildirim rapor gate through PersonelOrgStructureSchema::hasPersonelScopeColumns", () => {
    const login = read("api/src/Auth/LoginController.php");
    expect(login).toContain("PersonelOrgStructureSchema");
    expect(login).toContain("hasPersonelScopeColumns");
    expect(login).toContain("deriveSubeIdsFromOrgAssignments");

    const rapor = read("api/src/Services/BildirimPuantajEtkiRaporQueryService.php");
    expect(rapor).toContain("PersonelOrgStructureSchema::hasPersonelScopeColumns");
    expect(rapor).toContain("1=0");
    expect(rapor).toContain("p.bolum_id IN");
    expect(rapor).toContain("p.birim_id IN");

    const schema = read("api/src/Services/Personel/PersonelOrgStructureSchema.php");
    expect(schema).toContain("public static function hasPersonelScopeColumns");
    expect(schema).toContain("personelScopeProjection");
  });

  it("PHP hardening matrix PASS (legacy fail-closed + ready filters)", () => {
    const result = spawnSync(
      "php",
      [resolve(root, "tests/php/OrgScopeSchemaHardeningPhpTestRunner.php")],
      { encoding: "utf8", cwd: root }
    );
    if (result.status !== 0) {
      throw new Error(
        `OrgScopeSchemaHardening PHP runner failed (status=${result.status}):\n${result.stdout}\n${result.stderr}`
      );
    }
    expect(result.stdout).toContain("OK: LOGIN_BOLUM_ESKI_SEMA");
    expect(result.stdout).toContain("OK: LOGIN_BIRIM_ESKI_SEMA");
    expect(result.stdout).toContain("OK: BILDIRIM_RAPOR_BOLUM_ESKI_SEMA");
    expect(result.stdout).toContain("OK: BILDIRIM_RAPOR_BIRIM_ESKI_SEMA");
    expect(result.stdout).toContain("OK: LOGIN_READY_DERIVE");
    expect(result.stdout).toContain("OK: BILDIRIM_RAPOR_READY_FILTER");
    expect(result.stdout).toContain("ORG_SCOPE_SCHEMA_HARDENING=PASS");
  });
});
