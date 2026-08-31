import { beforeAll, describe, expect, it } from "vitest";
import { resolve } from "node:path";
import { ensureDisposableMariaDbEnv, runPhpMysqlRunner } from "../scripts/disposable-mariadb.mjs";

describe("canonical migration round 080 + 081 control plane (MariaDB runtime)", () => {
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
      "a production tip 079 database is apply-ready for the round",
      "the whole round is pending before the first apply",
      "the next authorized migration is 080",
      "the authorized checksum is the sha256 of the 080 file on this ref",
      "a targeted request applies exactly one migration",
      "081 stays pending behind its own request",
      "nothing from 081 leaked into the 080 request",
      "080 wrote no business row",
      "a full-chain verify refuses a partially applied round",
      "the chain between the two applies is still apply-ready",
      "the authorized checksum moved to the 081 file",
      "the half-applied round is reported as resumable, not as clean",
      "production tip is 081 after the round",
      "the round wrote no business row at all",
      "no existing user role value was remapped",
      "a completed round is not apply-ready again",
      "verify refuses a chain applied beyond its authorized target",
      "a target outside the canonical chain is refused",
      "verify-migration-round-control-plane-mysql: OK"
    ]) {
      expect(stdout, marker).toContain(marker);
    }
  }, 300_000);
});
