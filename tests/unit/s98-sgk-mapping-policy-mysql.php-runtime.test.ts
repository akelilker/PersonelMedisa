import { beforeAll, describe, expect, it } from "vitest";
import { resolve } from "node:path";
import { ensureDisposableMariaDbEnv, runPhpMysqlRunner } from "../scripts/disposable-mariadb.mjs";

const runnerPath = resolve(process.cwd(), "tests/php/S98SgkMappingPolicyMysqlTestRunner.php");

describe("S98 SGK mapping + policy MariaDB acceptance", () => {
  beforeAll(async () => {
    await ensureDisposableMariaDbEnv();
  }, 60_000);

  it("covers mapping/policy dual-control and S98-R1 decision contract (incl. migration 047)", () => {
    const result = runPhpMysqlRunner(runnerPath);
    expect(result.status, result.stderr || result.stdout).toBe(0);
    expect(result.stdout).toContain("verify-s98-mapping-policy: OK");
    expect(result.stdout).toContain("migration 047 applied + idempotent");
    expect(result.stdout).toContain("migration 048 personel_id applied + idempotent");
    expect(result.stdout).toContain("linked scoped prepare/approve PASS");
    expect(result.stdout).toContain("distinct persons dual-control PASS");
    expect(result.stdout).toContain("missing actor personel link code");
    expect(result.stdout).toContain("missing preparer personel link");
    expect(result.stdout).toContain("missing schema prepare code");
    expect(result.stdout).toContain("empty scope code");
    expect(result.stdout).toContain("wrong scope code");
    expect(result.stdout).toContain("duplicate personel_id unique rejected");
    expect(result.stdout).toContain("DAHIL + NULL code insert PASS");
    expect(result.stdout).toContain("fixture-like rows dry-run applyable");
    expect(result.stdout).toContain("roundPartialPrimDays(225)=30 cap");
    expect(result.stdout).toContain("NULL eksik_gun_kodu persisted as SQL NULL not empty string");
  });
});
