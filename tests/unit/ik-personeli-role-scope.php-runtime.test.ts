import { beforeAll, describe, expect, it } from "vitest";
import { resolve } from "node:path";
import { ensureDisposableMariaDbEnv, runPhpMysqlRunner } from "../scripts/disposable-mariadb.mjs";

describe("IK_PERSONELI role and login revocation audit: migration 081 (MariaDB runtime)", () => {
  beforeAll(async () => {
    await ensureDisposableMariaDbEnv();
  }, 90_000);

  it("applies migration 081 additively and keeps the revocation audit append-only", () => {
    const result = runPhpMysqlRunner(
      resolve(process.cwd(), "tests/php/IkPersoneliRoleScopeMysqlTestRunner.php")
    );
    expect(result.status, result.stderr || result.stdout).toBe(0);
    if (String(result.stdout).includes("SKIP:")) {
      expect(result.stdout).toContain("Disposable MariaDB");
      return;
    }

    const stdout = String(result.stdout);
    for (const marker of [
      "revocation audit reports not-ready before migration 081",
      "unauditable environment refuses the revocation write with 409",
      "IK_PERSONELI is not storable before migration 081",
      "migration chain 079 -> 081 up",
      "users.rol readback is the exact canonical catalog",
      "migration 081 is idempotent",
      "IK_PERSONELI is storable and no role value was truncated",
      "no blank role after the widening",
      "revocation audit row is written",
      "revocation audit records exact actor, preimage and postimage",
      "revocation audit row cannot be updated",
      "revocation audit row cannot be deleted",
      "an audited account cannot be hard deleted, so past attribution survives",
      "audit failure rolls back the deactivation it could not explain",
      "IkPersoneliRoleScopeMysqlTestRunner: ALL PASS"
    ]) {
      expect(stdout, marker).toContain(marker);
    }
  }, 300_000);
});
