import { beforeAll, describe, expect, it } from "vitest";
import { resolve } from "node:path";
import { ensureDisposableMariaDbEnv, runPhpMysqlRunner } from "../scripts/disposable-mariadb.mjs";

describe("canonical migration round 084 control plane (MariaDB runtime)", () => {
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
      "the preimage database is at production tip 083",
      "a production tip 083 database is apply-ready for the 084 round",
      "only 084 is pending before the apply",
      "the next authorized migration is 084",
      "preflight resolves the pending 084 checksum",
      "084 creates no new audit table so the round-audit preimage stays empty",
      "the preimage proves the completed 083 round is really present",
      "the clean preimage does not yet carry okundu_mi",
      "the clean preimage does not yet carry toplam_personel",
      "a targeted request applies exactly one migration",
      "production tip is 084 after the apply",
      "084 added okundu_mi",
      "084 added toplam_personel",
      "the previous round owner personel_organizasyon_degisiklik_auditleri survived 084",
      "084 wrote no business row",
      "084 wrote no completion row",
      "a completed round is not apply-ready again",
      "a target outside the canonical chain is refused",
      "verify-migration-round-control-plane-mysql: OK"
    ]) {
      expect(stdout, marker).toContain(marker);
    }
  }, 300_000);
});
