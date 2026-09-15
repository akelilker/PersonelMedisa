import { beforeAll, describe, expect, it } from "vitest";
import { resolve } from "node:path";
import { ensureDisposableMariaDbEnv, runPhpMysqlRunner } from "../scripts/disposable-mariadb.mjs";

describe("organization mapping owners: inventory, preflight, backup, transactional apply and postcheck (MariaDB runtime)", () => {
  beforeAll(async () => {
    await ensureDisposableMariaDbEnv();
  }, 90_000);

  it("proves the inventory is read-only and exact, and the mapping is preimage-gated, idempotent and fail-closed", () => {
    const result = runPhpMysqlRunner(
      resolve(process.cwd(), "tests/php/OrganizationMappingMysqlTestRunner.php")
    );
    expect(result.status, result.stderr || result.stdout).toBe(0);
    if (String(result.stdout).includes("SKIP:")) {
      expect(result.stdout).toContain("Disposable MariaDB");
      return;
    }

    for (const marker of [
      // inventory
      "the inventory leaves every organisation row byte-identical",
      "the production-shaped inventory reports PASS",
      "schema is ready and data is not, exactly as production reports after 079",
      "the exact branch id set is reported, gap included",
      "branch id 3 is reported absent, never invented",
      "the observed branch set matches the documented one with no difference",
      "each branch row carries its exact id, kod, ad, durum and both relations",
      "each branch row carries its personnel, location and assignment counts",
      "an existing branch assignment is counted on the branch that holds it",
      "each payroll employer carries its provable branch links and personnel count",
      "each work location carries its exact row plus its personnel count",
      "every work location is inventoried",
      "the location x branch matrix is exact, anonymous and deterministically ordered",
      "personnel without a work location are aggregated per branch, exactly",
      "matrix total plus without-location total equals every personnel row",
      "the matrix reconciles against the personnel count and raises no mismatch blocker",
      "the matrix publishes relation ids and a count, and nothing else",
      "the extended inventory contract is published as schema version 6",
      "the personnel evidence read is SELECT-only: every bounded organisation row stays byte-identical",
      "only the bounded allowlist rows are published: two by canonical id, one resolved from its identity",
      "each published row states which allowlist key admitted it",
      "a fully populated personnel row outside both allowlist keys never reaches the payload",
      "an ambiguous identity publishes no row at all",
      "a read that stays inside the allowlist raises no boundary violation blocker",
      "a business-truth identity resolves once, is reported unresolved, or publishes nothing as ambiguous",
      "the bounded id allowlist reports every member it could not find instead of widening itself",
      "bagli_amir_id is a users.id and its identity is copied from the published user allowlist",
      "the published bagli_amir_id is a users.id, not a personnel id",
      "a manager outside the user allowlist contributes the relation id and no identity",
      "each bounded row publishes its canonical org relations with catalogue labels only",
      "a baseline-only database is provable with no extension at all",
      "scope totals are reported and both new scope tables are proven empty",
      "branch assignments are grouped per role without naming a user",
      "no personnel name and no username reaches the inventory payload",
      "two inventories of an unchanged database produce the same checksum",
      "the published checksum is reproducible from the data section alone",
      // spec validation
      "a valid spec pins the exact inventory it was written against",
      "an unknown field is rejected instead of silently ignored",
      "a duplicate company code is rejected",
      "a duplicate branch mapping is rejected",
      "branch id 3 is refused by the spec, so it can never be created",
      "a spec that decides only some branches is rejected",
      "a branch may target a company other than its payroll employer company",
      "a branch employer the spec never decides is rejected",
      "a mapping that targets an undeclared company is rejected",
      "a spec with no location section at all is valid: locations may be deferred entirely",
      "the committed test-only fixture is a structurally valid spec",
      // preflight
      "the preflight passes on the exact inventory it pins",
      "the preflight writes nothing at all",
      "the preflight publishes the exact delta it would apply",
      "only approved short names are planned for rename; ids 7 and 11 keep their name",
      "unresolved work locations are planned as deferred, not guessed",
      "a deploy sha other than the authorized one blocks the operation",
      "an unexpected production migration tip blocks the operation",
      "a pending migration blocks the mapping operation",
      "an edited inventory payload fails the checksum instead of being trusted",
      "a branch whose name moved after the inventory fails the preimage check",
      "apply refuses to start without a real backup digest",
      "apply refuses to start on an unverified backup",
      "a backup-blocked apply leaves the database untouched",
      // backup
      "the mapping backup is read back and verified",
      "the mapping backup scope covers every table the operation can change",
      "the backup manifest records the exact preimage row counts",
      "the manifest pins the operation, the authorized sha and both checksums",
      "the manifest carries index and foreign-key metadata for the backed-up owners",
      "the published metadata never carries the server path",
      "the dump exists on disk outside the webroot",
      "the dump on disk hashes to the published digest",
      // apply
      "exactly the approved companies are created",
      "every branch and payroll employer is mapped in one operation",
      "exactly the eight approved short names are applied",
      "provable locations are mapped and unresolved ones stay deferred",
      "the company prefix is removed only where a short name was approved",
      "the two branches with no approved short name keep their exact original name",
      "no branch code is changed",
      "every branch id survives and no id 3 is created",
      "row counts change only where the operation creates a company",
      "personeller and every user scope table survive byte-identical",
      "no user scope is granted by the mapping operation",
      // postcheck
      "the postcheck reports PASS with no unexpected delta",
      "data_ready turns true once companies, branches and payroll employers are mapped",
      "three work locations are still unmapped",
      "deferred work locations do not block readiness",
      "the postcheck carries the backup reference of the operation that produced it",
      "the postcheck evidences every company and every branch decision",
      // idempotence and partial states
      "a replay of the same operation is an idempotent no-op",
      "the idempotent replay changes no row",
      "an incompatible partial state blocks the apply",
      "the blocker names the branch preimage that no longer matches",
      "the blocked apply rolls nothing forward: the partial state is untouched",
      "a compatible same-target partial state converges and finishes the rest",
      "the converged database reaches data_ready as well",
      // conflicts and rollback
      "an existing company code with a different name is a blocker",
      "the company conflict blocks the apply",
      "the company-conflict rollback leaves every row as it was",
      "a company name already used under another code is a blocker",
      "an employer already pinned to another company is refused",
      "the employer-company conflict rollback restores the exact preimage",
      "a mid-transaction database error aborts the operation",
      "no transaction is left open after the failure",
      "the mid-transaction error rolls every write back",
      // baseline + audited branch extensions
      "baseline only: PASS with an empty extension set",
      "an extension branch without a create audit is a blocker",
      "an unprovable extension never grows the expected branch count",
      "audited extensions 12 and 13 are accepted with no blocker",
      "the expected branch set and count are derived: baseline plus audited extensions",
      "a future audited extension passes with no code change",
      "the audit owner is readable, so extension proof is evaluable",
      "the classification is SELECT-only: only the fixture changed rows",
      "two create audits for one branch block instead of counting as proof",
      "a create audit that does not match the live branch identity blocks",
      "a later rename or status change does not invalidate the create audit",
      "a branch mapped to another company payroll employer is not a blocker",
      "every orphan counter is still published",
      "a missing baseline branch is still a blocker",
      "an audit cannot legitimise branch id 3"
    ]) {
      expect(result.stdout).toContain(marker);
    }

    expect(result.stdout).toContain("verify-organization-mapping-mysql: OK");
  });
});
