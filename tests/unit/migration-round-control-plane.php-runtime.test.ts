import { beforeAll, describe, expect, it } from "vitest";
import { resolve } from "node:path";
import { ensureDisposableMariaDbEnv, runPhpMysqlRunner } from "../scripts/disposable-mariadb.mjs";

describe("canonical migration round 083 control plane (MariaDB runtime)", () => {
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
      "the preimage database is at production tip 082",
      "a production tip 082 database is apply-ready for the 083 round",
      "only 083 is pending before the apply",
      "the next authorized migration is 083",
      "the clean preimage does not yet carry the personnel org audit table",
      "the preimage proves the completed 082 round is really present",
      "a targeted request applies exactly one migration",
      "production tip is 083 after the apply",
      "083 created the personnel organisation audit owner",
      "the previous round owner user_erisim_degisiklik_auditleri survived 083",
      "083 wrote no business row",
      "a completed round is not apply-ready again",
      "a target outside the canonical chain is refused",
      "verify-migration-round-control-plane-mysql: OK"
    ]) {
      expect(stdout, marker).toContain(marker);
    }
  }, 300_000);
});
