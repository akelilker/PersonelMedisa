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

  it("runs historical exit-date correction safety gates, durable audit, retention, rollback", () => {
    const result = runPhpMysqlRunner(runnerPath);
    expect(result.status, result.stderr || result.stdout).toBe(0);
    if (String(result.stdout).includes("SKIP:")) {
      expect(result.stdout).toContain("Disposable MariaDB");
      return;
    }
    expect(result.stdout).toContain("ALL_PASS PersonelHistoricalExitDateCorrectionMysqlTestRunner");
    for (const marker of [
      "old baslangic + old bitis => READY",
      "old baslangic + wrong bitis => FAIL",
      "wrong baslangic + old bitis => FAIL",
      "correction same surec id preserves",
      "PASIF remains PASIF",
      "original İşveren feshi preserved",
      "durable audit persisted",
      "audit contains mutation_id + actor + old/new + old_aciklama",
      "resolveTerminationDate = corrected HR date",
      "corrected lifecycle manifest becomes current",
      "retention_until from corrected date",
      "old manifest remains immutable",
      "old manifest current olarak seçilmez",
      "same logical retry => ALREADY_APPLIED",
      "arbitrary third date => FAIL",
      "locked apply both fields revalidates",
      "rollback restores surec date",
      "transaction failure rolls back audit",
      "transaction failure rolls back retention remint",
      "normal exit owner unchanged",
      "AKTIF target rejected by historical correction",
    ]) {
      expect(result.stdout, marker).toContain(`[PASS] ${marker}`);
    }
  });
});
