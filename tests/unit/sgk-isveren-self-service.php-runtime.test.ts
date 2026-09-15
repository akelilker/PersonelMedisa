import { beforeAll, describe, expect, it } from "vitest";
import { resolve } from "node:path";
import { ensureDisposableMariaDbEnv, runPhpMysqlRunner } from "../scripts/disposable-mariadb.mjs";

describe("SGK employer self-service: catalog CRUD, branch mapping and fail-closed rules (MariaDB runtime)", () => {
  beforeAll(async () => {
    await ensureDisposableMariaDbEnv();
  }, 90_000);

  it("owns the employer catalog and keeps every şube mapping on the active-employer rule", () => {
    const result = runPhpMysqlRunner(
      resolve(process.cwd(), "tests/php/SgkIsverenSelfServiceMysqlTestRunner.php")
    );
    expect(result.status, result.stderr || result.stdout).toBe(0);
    if (String(result.stdout).includes("SKIP:")) {
      expect(result.stdout).toContain("Disposable MariaDB");
      return;
    }

    for (const marker of [
      // catalog read
      "catalog read exposes company, soft state and branch count",
      "one company may own several active employers",
      // create / update / soft state
      "an authorised create trims and persists the employer",
      "create fails closed without a company",
      "create fails closed for an unknown company id",
      "create fails closed without a code",
      "create fails closed without a name",
      "a duplicate employer code is refused",
      "a duplicate employer name is refused regardless of case",
      "update writes code, name and the soft AKTIF/PASIF state",
      "a pasif employer can be activated again",
      "update refuses a code already used by another employer",
      "update refuses an unknown soft state",
      "update cannot unmap an employer from its company",
      // branch mapping
      "a pasif employer cannot be attached to a new branch",
      "another company employer can be attached to this branch",
      "an employer without a company can be attached to a branch",
      "an unknown employer id is refused on branch create",
      "branch create persists subeler.sgk_isveren_id",
      "one employer can serve many branches",
      "branch update persists a changed employer",
      "an unchanged pasif mapping is preserved instead of silently rewritten",
      "branch update refuses an explicit switch to a pasif employer",
      "branch update accepts another company employer",
      // company change fail-closed
      "a branch pointing at the employer does not block an employer company change",
      "an unmapped employer can be bound to a company from the management screen",
      "an unreferenced employer can be re-mapped to another company",
      "a company change that would desync a stored personnel relation is refused",
      // delete guard
      "an employer referenced by branches cannot be physically deleted",
      "an employer referenced only by personnel cannot be physically deleted",
      "an employer referenced only by a user scope cannot be physically deleted",
      "an unreferenced employer can still be removed physically",
      "a deleted employer is no longer readable",
      "deleting an unknown employer answers not found",
      // unchanged personnel invariant
      "PersonelSgkCompanyConsistency still accepts a same-company employer",
      "branch-default employer equality is still not required for personnel",
      "PersonelSgkCompanyConsistency still fails closed on a foreign-company employer",
      // Employment-scope parity: a Harici personel never blocks an employer company change.
      "a DIS_KAYNAK personnel row does not block an employer company change"
    ]) {
      expect(result.stdout).toContain(marker);
    }

    expect(result.stdout).toContain("verify-sgk-isveren-self-service-mysql: OK");
  });
});
