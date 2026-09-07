import { beforeAll, describe, expect, it } from "vitest";
import { resolve } from "node:path";
import { ensureDisposableMariaDbEnv, runPhpMysqlRunner } from "../scripts/disposable-mariadb.mjs";

describe("canonical migration round 085-088 control plane (MariaDB runtime)", () => {
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
      "a production tip 084 database is apply-ready for the 085-088 round",
      "085, 086, 087 and 088 are pending before the apply",
      "the next authorized migration is 085",
      "preflight resolves the pending 085 checksum",
      "a targeted request applies exactly one migration",
      "production tip is 085 after the apply",
      "086, 087 and 088 remain pending after 085",
      "tip 085 with 086+087+088 pending remains apply-ready",
      "preflight resolves the pending 086 checksum",
      "a targeted request applies exactly 086",
      "production tip is 086 after the apply",
      "087 and 088 remain pending after 086",
      "tip 086 with 087+088 pending remains apply-ready",
      "preflight resolves the pending 087 checksum",
      "a targeted request applies exactly 087",
      "production tip is 087 after the apply",
      "088 remains pending after 087",
      "087 created the branch accounting ACL owner",
      "tip 087 with 088 pending remains apply-ready",
      "preflight resolves the pending 088 checksum",
      "a targeted request applies exactly 088",
      "production tip is 088 after the apply",
      "088 created the branch manager assignment owner",
      "088 wrote no business row",
      "a completed round is not apply-ready again",
      "a target outside the canonical chain is refused",
      "verify-migration-round-control-plane-mysql: OK"
    ]) {
      expect(stdout, marker).toContain(marker);
    }
  });
});
