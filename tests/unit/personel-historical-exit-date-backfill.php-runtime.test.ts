import { beforeAll, describe, expect, it } from "vitest";
import { resolve } from "node:path";
import {
  ensureDisposableMariaDbEnv,
  runPhpMysqlRunner
} from "../scripts/disposable-mariadb.mjs";

const runnerPath = resolve(
  process.cwd(),
  "tests/php/PersonelHistoricalExitDateBackfillMysqlTestRunner.php"
);

describe("Personel HISTORICAL_EXIT_DATE_BACKFILL MariaDB runtime", () => {
  beforeAll(async () => {
    await ensureDisposableMariaDbEnv();
  }, 90_000);

  it("runs historical PASIF residual backfill gates and rollback", () => {
    const result = runPhpMysqlRunner(runnerPath);
    expect(result.status, result.stderr || result.stdout).toBe(0);
    expect(result.stdout).toContain("ALL_HISTORICAL_EXIT_BACKFILL_TESTS_PASSED");
    expect(result.stdout).toContain("[PASS] plan create action for PASIF missing exit");
    expect(result.stdout).toContain("[PASS] plan is not no_change for missing exit");
    expect(result.stdout).toContain("[PASS] plan keeps authoritative exit date");
    expect(result.stdout).toContain("[PASS] plan does not insert surec");
    expect(result.stdout).toContain("[PASS] plan keeps PASIF");
    expect(result.stdout).toContain("[PASS] first apply is not already_applied");
    expect(result.stdout).toContain("[PASS] apply returns surec_id");
    expect(result.stdout).toContain("[PASS] apply keeps PASIF");
    expect(result.stdout).toContain("[PASS] apply inserts ISTEN_AYRILMA surec");
    expect(result.stdout).toContain("[PASS] surec turu ISTEN_AYRILMA");
    expect(result.stdout).toContain("[PASS] surec date matches authoritative");
    expect(result.stdout).toContain("[PASS] surec aciklama marked as historical backfill");
    expect(result.stdout).toContain("[PASS] same date apply is already_applied");
    expect(result.stdout).toContain("[PASS] idempotent apply does not duplicate surec");
    expect(result.stdout).toContain("[PASS] conflicting exit date rejected");
    expect(result.stdout).toContain("[PASS] existing different ISTEN_AYRILMA date rejected");
    expect(result.stdout).toContain("[PASS] matching existing exit date is already_applied");
    expect(result.stdout).toContain("[PASS] AKTIF target rejected by historical backfill");
    expect(result.stdout).toContain("[PASS] normal exit owner still rejects PASIF");
    expect(result.stdout).toContain("[PASS] future exit date rejected");
    expect(result.stdout).toContain("[PASS] exit before hire rejected");
    expect(result.stdout).toContain("[PASS] missing personel rejected");
    expect(result.stdout).toContain("[PASS] apply conflict rolls back caller transaction path");
    expect(result.stdout).toContain("[PASS] rolled-back conflict leaves no partial surec");
    expect(result.stdout).toContain("[PASS] retention clock available");
  });
});
