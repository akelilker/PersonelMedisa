import { beforeAll, describe, expect, it } from "vitest";
import { resolve } from "node:path";
import { ensureDisposableMariaDbEnv, runPhpMysqlRunner } from "../scripts/disposable-mariadb.mjs";

const runnerPath = resolve(process.cwd(), "tests/php/ActorIdentityLifecycleMysqlTestRunner.php");

describe("actor identity nullable-personnel MariaDB acceptance", () => {
  beforeAll(async () => {
    await ensureDisposableMariaDbEnv();
  }, 60_000);

  it("supports attributable non-personnel actors without weakening verification", () => {
    const result = runPhpMysqlRunner(runnerPath);
    expect(result.status, result.stderr || result.stdout).toBe(0);
    expect(result.stdout).toContain("NON_PERSONEL_FORMAL_ACTOR_CREATE personel NULL");
    expect(result.stdout).toContain("NON_PERSONEL_FORMAL_ACTOR_VERIFY");
    expect(result.stdout).toContain("NON_PERSONEL_FORMAL_ACTOR_BIND");
    expect(result.stdout).toContain("PERSONEL_LINKED_ACTOR personel preserved");
    expect(result.stdout).toContain("SEDANUR_FLOW_REGRESSION");
    expect(result.stdout).toContain("ARBITRARY_NULL_PERSONEL_BIND fail closed");
    expect(result.stdout).toContain("SELF_VERIFY_BEFORE_BIND fail closed");
    expect(result.stdout).toContain("SELF_VERIFY_AFTER_BIND fail closed");
    expect(result.stdout).toContain("SAME_PERSON_DUAL_CONTROL fail closed");
    expect(result.stdout).toContain("verify-actor-identity-lifecycle: OK");
  });
});
