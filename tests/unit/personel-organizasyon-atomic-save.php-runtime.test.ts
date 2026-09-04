import { beforeAll, describe, expect, it } from "vitest";
import { resolve } from "node:path";
import { ensureDisposableMariaDbEnv, runPhpMysqlRunner } from "../scripts/disposable-mariadb.mjs";

describe("personel organizasyon atomic save (MariaDB runtime)", () => {
  beforeAll(async () => {
    await ensureDisposableMariaDbEnv();
  }, 90_000);

  it("applies org + amir + tip atomically and rolls back on work-info failure", () => {
    const result = runPhpMysqlRunner(
      resolve(process.cwd(), "tests/php/PersonelOrganizasyonAtomicSaveMysqlTestRunner.php")
    );
    expect(result.status, result.stderr || result.stdout).toBe(0);
    if (String(result.stdout).includes("SKIP:")) {
      expect(result.stdout).toContain("Disposable MariaDB");
      return;
    }

    const stdout = String(result.stdout);
    for (const marker of [
      "combined save resolves olay_tipi from tracked axis",
      "combined save changes org + work info fields",
      "org + amir + tip single save SUCCESS",
      "structured audit row written on SUCCESS",
      "invalid amir aborts combined save",
      "second-stage-style failure leaves no field changed",
      "failed attempt left no extra audit row",
      "generic protected-org PUT still DENY",
      "work-info-only generic PUT remains allowed for bulk owners"
    ]) {
      expect(stdout).toContain(marker);
    }
    expect(stdout).toContain("verify-personel-organizasyon-atomic-save-mysql: OK");
    expect(stdout).not.toContain("[FAIL]");
  });
});
