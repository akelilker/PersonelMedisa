import { beforeAll, describe, expect, it } from "vitest";
import { readFileSync } from "node:fs";
import { resolve } from "node:path";
import { ensureDisposableMariaDbEnv, runPhpMysqlRunner } from "../scripts/disposable-mariadb.mjs";

const runnerPath = resolve(process.cwd(), "tests/php/MukerrerHesapRaporuMysqlTestRunner.php");

describe("mükerrer hesap raporu (salt okuma, t900 B)", () => {
  beforeAll(async () => {
    await ensureDisposableMariaDbEnv();
  }, 60_000);

  it("üç kaynağı gruplar, veri değiştirmez, T.C. yazmaz", () => {
    const result = runPhpMysqlRunner(runnerPath);
    expect(result.status, result.stderr || result.stdout).toBe(0);
    expect(result.stdout).toContain("MUKERRER_READ_ONLY");
    expect(result.stdout).toContain("MUKERRER_NO_TC");
    expect(result.stdout).toContain("verify-mukerrer-hesap-raporu: OK");
  });

  it("servis ve CLI yazma sorgusu içermez", () => {
    const service = readFileSync(resolve("api/src/Services/Auth/MukerrerHesapRaporuService.php"), "utf8");
    const cli = readFileSync(resolve("api/bin/mukerrer-hesap-raporu.php"), "utf8");
    for (const src of [service, cli]) {
      expect(src).not.toMatch(/\b(INSERT|UPDATE|DELETE|REPLACE|ALTER|DROP|TRUNCATE)\b/);
    }
    expect(cli).toContain("SET SESSION TRANSACTION READ ONLY");
    expect(cli).toContain("rollBack()");
  });
});
