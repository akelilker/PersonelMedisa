import { afterAll, beforeAll, describe, expect, it } from "vitest";
import { resolve } from "node:path";
import {
  ensureDisposableMariaDbEnv,
  runPhpMysqlRunner
} from "../scripts/disposable-mariadb.mjs";

const runnerPath = resolve(process.cwd(), "tests/php/PersonelSecureOnboardingMysqlTestRunner.php");

describe("PersonelSecureOnboarding disposable MariaDB acceptance", () => {
  beforeAll(async () => {
    await ensureDisposableMariaDbEnv();
  }, 90_000);

  afterAll(() => undefined);

  it("proves migration safety, onboarding, token, concurrency, and DIS_KAYNAK gates", () => {
    const result = runPhpMysqlRunner(runnerPath);
    expect(result.status, result.stderr || result.stdout).toBe(0);
    expect(result.stdout).toContain("[DONE] PersonelSecureOnboardingMysqlTestRunner");
    expect(result.stdout).toContain("[PASS] concurrent reissue leaves at most one valid invitation");
    expect(result.stdout).toContain("[PASS] concurrent redeem exactly one success");
    expect(result.stdout).toContain("[PASS] competing reissue waited on locked user row");
    expect(result.stdout).toContain("[PASS] generic PERSONEL+personel_id create blocked PERSONEL_USE_SECURE_ONBOARDING");
  });
});
