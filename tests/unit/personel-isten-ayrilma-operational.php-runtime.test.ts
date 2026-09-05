import { beforeAll, describe, expect, it } from "vitest";
import { resolve } from "node:path";
import {
  ensureDisposableMariaDbEnv,
  runPhpMysqlRunner
} from "../scripts/disposable-mariadb.mjs";

const runnerPath = resolve(process.cwd(), "tests/php/PersonelIstenAyrilmaMysqlTestRunner.php");

describe("Personel ISTEN_AYRILMA MariaDB runtime", () => {
  beforeAll(async () => {
    await ensureDisposableMariaDbEnv();
  }, 90_000);

  it("runs termination acceptance gates and atomic rollback", () => {
    const result = runPhpMysqlRunner(runnerPath);
    expect(result.status, result.stderr || result.stdout).toBe(0);
    expect(result.stdout).toContain("verify-personel-isten-ayrilma-mysql: OK");
    expect(result.stdout).toContain("[PASS] invalid exit date denied");
    expect(result.stdout).toContain("[PASS] exit before hire denied");
    expect(result.stdout).toContain("[PASS] tomorrow exit denied");
    expect(result.stdout).toContain("[PASS] tomorrow deny keeps AKTIF");
    expect(result.stdout).toContain("[PASS] tomorrow deny writes no surec");
    expect(result.stdout).toContain("[PASS] tomorrow deny writes no retention");
    expect(result.stdout).toContain("[PASS] PASIF personel second exit denied");
    expect(result.stdout).toContain("[PASS] today exit sets PASIF");
    expect(result.stdout).toContain("[PASS] past exit sets PASIF");
    expect(result.stdout).toContain("[PASS] second exit after PASIF denied");
    expect(result.stdout).toContain("[PASS] atomic rollback keeps personel AKTIF");
  });
});
