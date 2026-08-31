import { beforeAll, describe, expect, it } from "vitest";
import { resolve } from "node:path";
import { ensureDisposableMariaDbEnv, runPhpMysqlRunner } from "../scripts/disposable-mariadb.mjs";

describe("canonical migration round 082 control plane (MariaDB runtime)", () => {
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
      "the preimage database is at production tip 081",
      "a production tip 081 database is apply-ready for the 082 round",
      "only 082 is pending before the apply",
      "the next authorized migration is 082",
      "the authorized checksum is the sha256 of the 082 file on this ref",
      "the preimage proves the completed 080/081 round is really present",
      "an empty pending set on a non-round tip is named rather than read as complete",
      "a ledger that claims 081 without its audit owner is refused",
      "restoring the predecessor owner makes the round apply-ready again",
      "a targeted request applies exactly one migration",
      "production tip is 082 after the apply",
      "082 created the access change audit owner",
      "the previous round owner user_erisim_kaldirma_auditleri survived 082",
      "082 wrote no business row",
      "no existing user role value was remapped",
      "no already-applied ledger row or checksum was rewritten by the round",
      "a completed round is not apply-ready again",
      "verify refuses a chain applied beyond its authorized target",
      "a target outside the canonical chain is refused",
      "verify-migration-round-control-plane-mysql: OK"
    ]) {
      expect(stdout, marker).toContain(marker);
    }
  }, 300_000);
});
