import { beforeAll, describe, expect, it } from "vitest";
import { resolve } from "node:path";
import {
  ensureDisposableMariaDbEnv,
  runPhpMysqlRunner
} from "../scripts/disposable-mariadb.mjs";

const runnerPath = resolve(
  process.cwd(),
  "tests/php/PersonelHistoricalExitDateCorrectionMysqlTestRunner.php"
);

describe("Personel HISTORICAL_EXIT_DATE_CORRECTION MariaDB runtime", () => {
  beforeAll(async () => {
    await ensureDisposableMariaDbEnv();
  }, 90_000);

  it("runs historical exit-date correction gates, apply, audit, retention remint, rollback", () => {
    const result = runPhpMysqlRunner(runnerPath);
    expect(result.status, result.stderr || result.stdout).toBe(0);
    expect(result.stdout).toContain("ALL_PASS PersonelHistoricalExitDateCorrectionMysqlTestRunner");
    expect(result.stdout).toContain("[PASS] plan correct action for exact preimage");
    expect(result.stdout).toContain("[PASS] plan preserves surec_id");
    expect(result.stdout).toContain("[PASS] plan does not mutate surec");
    expect(result.stdout).toContain("[PASS] apply keeps surec_id 38");
    expect(result.stdout).toContain("[PASS] apply keeps PASIF");
    expect(result.stdout).toContain("[PASS] no duplicate ISTEN_AYRILMA");
    expect(result.stdout).toContain("[PASS] baslangic corrected");
    expect(result.stdout).toContain("[PASS] audit records old/new + operation_type");
    expect(result.stdout).toContain("[PASS] retention manifests reminted for corrected date");
    expect(result.stdout).toContain("[PASS] same date is already_applied");
    expect(result.stdout).toContain("[PASS] old date mismatch rejected");
    expect(result.stdout).toContain("[PASS] wrong surec_id rejected");
    expect(result.stdout).toContain("[PASS] wrong personel_id rejected");
    expect(result.stdout).toContain("[PASS] exit before hire rejected");
    expect(result.stdout).toContain("[PASS] future date rejected");
    expect(result.stdout).toContain("[PASS] second conflicting ISTEN_AYRILMA rejected");
    expect(result.stdout).toContain("[PASS] rollback restores surec date");
    expect(result.stdout).toContain("[PASS] normal exit owner remains AKTIF-gated");
    expect(result.stdout).toContain("[PASS] AKTIF target rejected by historical correction");
  });
});
