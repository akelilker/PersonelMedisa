import { beforeAll, describe, expect, it } from "vitest";
import { resolve } from "node:path";
import { ensureDisposableMariaDbEnv, runPhpMysqlRunner } from "../scripts/disposable-mariadb.mjs";

const runnerPath = resolve(process.cwd(), "tests/php/PuantajRaporXlsxMysqlTestRunner.php");

describe("puantaj raporu xlsx MariaDB", () => {
  beforeAll(async () => {
    await ensureDisposableMariaDbEnv();
  }, 90_000);

  it("writes a scoped xlsx workbook from the puantaj report", () => {
    const result = runPhpMysqlRunner(runnerPath);
    expect(result.status, result.stderr || result.stdout).toBe(0);
    expect(result.stdout).toContain("[PASS] xlsx zip signature");
    expect(result.stdout).toContain("[PASS] xlsx content-type");
    expect(result.stdout).toContain("[PASS] xlsx month filename");
    expect(result.stdout).toContain("[PASS] xlsx range filename");
    expect(result.stdout).toContain("[PASS] BA xlsx includes own birim row");
    expect(result.stdout).toContain("[PASS] BA xlsx excludes other birim personel");
    expect(result.stdout).toContain("[PASS] BA xlsx excludes other sube personel");
    expect(result.stdout).toContain("[PASS] BA json personel matches xlsx scope");
    expect(result.stdout).toContain("verify-puantaj-raporu-xlsx-mysql: OK");
  });
});
