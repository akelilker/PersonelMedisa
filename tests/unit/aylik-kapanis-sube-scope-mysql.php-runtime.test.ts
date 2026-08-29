import { beforeAll, describe, expect, it } from "vitest";
import { resolve } from "node:path";
import { ensureDisposableMariaDbEnv, runPhpMysqlRunner } from "../scripts/disposable-mariadb.mjs";

describe("aylik kapanis branch scope and approval actor (MariaDB runtime)", () => {
  beforeAll(async () => {
    await ensureDisposableMariaDbEnv();
  }, 90_000);

  it("keeps branch boundaries, persists the approving actor and denies self-approval", () => {
    const result = runPhpMysqlRunner(
      resolve(process.cwd(), "tests/php/AylikKapanisSubeScopeMysqlTestRunner.php")
    );
    expect(result.status, result.stderr || result.stdout).toBe(0);
    if (String(result.stdout).includes("SKIP:")) {
      expect(result.stdout).toContain("Disposable MariaDB");
      return;
    }

    for (const marker of [
      "canonical branch is resolved from closing rows, not echoed from the request",
      "unrestricted actor resolves every branch present in the month",
      "empty-scope-free branch actor resolves only its own branch",
      "cross-branch target resolves to no canonical branch for a scoped actor",
      "requested branch is ANDed with the assignment instead of replacing it",
      "cross-branch deny: a foreign sube_id matches no row even without the gate in front",
      "empty-scope deny is delegated to the canonical OrgScope owner on read and write",
      "closed branch reaches KAPANDI on its own row",
      "closing branch 1 never writes branch 2 state",
      "closing branch 1 never closes another branch rows",
      "state is one row per (ay, sube_id), not one global row per month",
      "revision moves only its own branch state",
      "branch-restricted actor sees only its own branch state",
      "cross-branch view folds to the most blocking state, never to a false KAPANDI",
      "count/state parity: summary total equals the returned rows",
      "cross-branch rows never leak into a scoped payload",
      "section approver is persisted and queryable on the row",
      "same user closing rows it approved is a DualControl violation",
      "self-approval is denied with the canonical SELF_APPROVAL_FORBIDDEN code",
      "a distinct final approver passes separation of duties",
      "unknown section approver fails closed instead of being auto-approved",
      "same actor identity is denied with SAME_ACTOR_IDENTITY_FORBIDDEN",
      "branch scope is reported unsupported on a pre-079 schema",
      "pre-079 aggregate behaviour is preserved verbatim, so nothing breaks before migration apply",
      "no caller passes the month alone to the state sync any more",
      "ay-kapat runs the separation-of-duties gate before closing",
      "both approval steps persist their acting user",
      "dead bildirimler.cancel grant is removed from the backend matrix"
    ]) {
      expect(result.stdout).toContain(marker);
    }

    expect(result.stdout).toContain("verify-aylik-kapanis-sube-scope-mysql: OK");
  });
});
