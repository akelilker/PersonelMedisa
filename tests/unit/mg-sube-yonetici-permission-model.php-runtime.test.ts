import { execFileSync, spawnSync } from "node:child_process";
import { dirname, resolve } from "node:path";
import { describe, expect, it } from "vitest";

const runnerPath = resolve(process.cwd(), "tests/php/MgSubeYoneticiPermissionModelPhpTestRunner.php");

describe("MG SUBE_YONETICISI permission model php runtime", () => {
  it("covers branch-scoped operational writes, forbidden closures and dual-control chain", () => {
    const isWindows = process.platform === "win32";
    const phpPath = isWindows
      ? execFileSync("where.exe", ["php"], { encoding: "utf8" }).split(/\r?\n/)[0].trim()
      : "php";
    const phpArgs = isWindows
      ? ["-d", `extension_dir=${resolve(dirname(phpPath), "ext")}`, runnerPath]
      : [runnerPath];
    const result = spawnSync(phpPath, phpArgs, { encoding: "utf8", cwd: process.cwd() });

    expect(result.status, result.stderr || result.stdout).toBe(0);
    expect(result.stdout).toContain("OK: SUBE_BRANCH_OPERATIONAL_DATA_ENTRY_AND_SUBMIT");
    expect(result.stdout).toContain("OK: SUBE_NO_FINALIZATION_SGK_FINANCE_ADMIN_RETENTION");
    expect(result.stdout).toContain("OK: SUBE_EMPTY_SCOPE_FAILS_CLOSED");
    expect(result.stdout).toContain("OK: GATE_DENIES_SUBE_YONETICISI: PUANTAJ_AYLIK_MUHURLE");
    expect(result.stdout).toContain("OK: GATE_DENIES_SUBE_YONETICISI: HAFTALIK_KAPANIS_CREATE");
    expect(result.stdout).toContain("OK: GATE_ADMITS_SUBE_YONETICISI: FAZLA_CALISMA_ODEME_TERCIHI_PUT");
    expect(result.stdout).toContain("OK: GATE_ADMITS_SUBE_YONETICISI: SERBEST_ZAMAN_OLUSUM");
    expect(result.stdout).toContain("OK: NO_ALTERNATIVE_GATE_BETWEEN_CLOSURE_AND_OPERATIONAL");
    expect(result.stdout).toContain("OK: OLD_OVERLOADED_PERMISSION_FULLY_REMOVED");
    expect(result.stdout).toContain("OK: CENTRAL_CONTROL_CHAIN_SEPARATED");
    expect(result.stdout).toContain("OK: SELF_APPROVAL_REJECTED");
    expect(result.stdout).toContain("MG_SUBE_YONETICI_PERMISSION_MODEL=PASS");
  });
});
