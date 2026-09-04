import { beforeAll, describe, expect, it } from "vitest";
import { resolve } from "node:path";
import { ensureDisposableMariaDbEnv, runPhpMysqlRunner } from "../scripts/disposable-mariadb.mjs";

describe("canonical migration round 085 control plane (MariaDB runtime)", () => {
  beforeAll(async () => {
    await ensureDisposableMariaDbEnv();
  }, 90_000);

  it("stays apply-ready across the whole round and applies one migration per request", () => {
    const result = runPhpMysqlRunner(
      resolve(process.cwd(), "tests/php/MigrationRoundControlPlaneMysqlTestRunner.php")
    );
    expect(result.status, result.stderr || result.stdout).toBe(0);
    if (String(result.stdout).includes("SKIP:")) {
      expect(result.stdout).toContain("Disposable MariaDB");
      return;
    }

    const stdout = String(result.stdout);
    for (const marker of [
      "the preimage database is at production tip 084",
      "a production tip 084 database is apply-ready for the 085 round",
      "only 085 is pending before the apply",
      "the next authorized migration is 085",
      "preflight resolves the pending 085 checksum",
      "the clean preimage does not yet carry gunluk_bildirim_duzeltme_auditleri",
      "the preimage proves the completed predecessor audit owners are really present",
      "a targeted request applies exactly one migration",
      "production tip is 085 after the apply",
      "085 created the correction audit owner",
      "085 installed append-only UPDATE protection",
      "085 installed append-only DELETE protection",
      "the previous round owner personel_organizasyon_degisiklik_auditleri survived 085",
      "085 wrote no business row",
      "a completed round is not apply-ready again",
      "a target outside the canonical chain is refused",
      "verify-migration-round-control-plane-mysql: OK"
    ]) {
      expect(stdout, marker).toContain(marker);
    }
  }, 300_000);
});
