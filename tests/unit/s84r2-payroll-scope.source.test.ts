import { describe, expect, it } from "vitest";
import { execFileSync } from "node:child_process";
import { existsSync, readFileSync, readdirSync } from "node:fs";
import path from "node:path";

function hasPhpCli(): boolean {
  try {
    execFileSync("php", ["-v"], { encoding: "utf8" });
    return true;
  } catch {
    return false;
  }
}

describe("S84-R2 payroll scope sources", () => {
  it("migration 035 exists with personel_bordro_kapsamlari", () => {
    const migration = readFileSync("api/migrations/035_personel_bordro_kapsamlari.sql", "utf8");
    expect(migration).toContain("personel_bordro_kapsamlari");
    expect(migration).toContain("personel_bordro_kapsam_auditleri");
  });

  it("treats 035 as latest migration", () => {
    const files = readdirSync("api/migrations")
      .filter((name) => /^\d{3}_/.test(name))
      .sort();
    expect(files[files.length - 1]).toBe("037_sgk_resmi_kaynak_manifesti_v1.sql");
  });

  it("service contract version is S84R2_PAYROLL_SCOPE_V1", () => {
    const service = readFileSync("api/src/Services/PersonelBordroKapsamService.php", "utf8");
    expect(service).toContain("S84R2_PAYROLL_SCOPE_V1");
    expect(service).toContain("CONTRACT_VERSION");
  });

  it("router exposes bordro-kapsamlari routes", () => {
    const router = readFileSync("api/src/Router.php", "utf8");
    expect(router).toContain("bordro-kapsamlari");
    expect(router).toContain("bordro-kapsamlari/dry-run");
    expect(router).toContain("onaya-gonder");
    expect(router).toContain("PersonelBordroKapsamController");
  });

  it("snapshot resolves PAYROLL_SCOPE_EXCLUDED and payroll_scope_hash", () => {
    const snapshot = readFileSync("api/src/Services/MaasHesaplamaSnapshotService.php", "utf8");
    expect(snapshot).toContain("PAYROLL_SCOPE_EXCLUDED");
    expect(snapshot).toContain("payroll_scope_hash");
    expect(snapshot).toContain("PersonelBordroKapsamService");
  });

  it("preflight has PAYROLL_SCOPE_EXCLUDED messaging", () => {
    const preflight = readFileSync("api/src/Services/BordroHazirlikPreflightService.php", "utf8");
    expect(preflight).toContain("PAYROLL_SCOPE_EXCLUDED");
  });

  it("frontend endpoints, section, and role permissions are wired", () => {
    const endpoints = readFileSync("src/api/endpoints.ts", "utf8");
    const section = readFileSync(
      "src/features/personeller/components/personel-dosya/PersonelBordroKapsamSection.tsx",
      "utf8"
    );
    const roles = readFileSync("src/lib/authorization/role-permissions.ts", "utf8");
    expect(endpoints).toContain("personelBordroKapsamlari");
    expect(endpoints).toContain("bordro-kapsamlari");
    expect(section).toContain("personel-bordro-kapsam-card");
    expect(roles).toContain("personel_bordro_kapsam.view");
    expect(roles).toContain("personel_bordro_kapsam.manage");
    expect(roles).toContain("personel_bordro_kapsam.approve");
  });

  it("e2e spec and package script exist", () => {
    expect(existsSync("tests/e2e/s84r2-payroll-scope.spec.ts")).toBe(true);
    const pkg = JSON.parse(readFileSync("package.json", "utf8")) as {
      scripts?: Record<string, string>;
    };
    expect(pkg.scripts?.["e2e:s84r2"]).toContain("s84r2-payroll-scope.spec.ts");
  });
});

describe("S84-R2 PHP runner", () => {
  it.skipIf(!hasPhpCli())("runs payroll scope fingerprint and permission checks", () => {
    const runner = path.resolve("tests/php/S84R2PayrollScopeTestRunner.php");
    const output = execFileSync("php", [runner], { encoding: "utf8" });
    expect(output).toContain("S84R2 PHP runner OK");
  });
});
