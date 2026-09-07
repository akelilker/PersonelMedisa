import { describe, expect, it } from "vitest";
import {
  A1_APPROVER_CANDIDATES,
  A1_EXCLUDED_SENAY_LINKED,
  A1_REQUIRED_OUTPUT_COLUMNS,
  assertReadOnlySql,
  buildApproverProbePlan,
  loadApproverProbeSql,
  redactSecrets,
} from "../../scripts/ops/a1-approver-candidate-ro-probe.mjs";
import {
  loadPersonnelDecisionPack,
  validatePersonnelDecisionPack,
  PERSONNEL_DECISION_REQUIRED_COLUMNS,
} from "../../scripts/ops/personnel-residual-decision-report.mjs";

describe("A1 approver RO probe helper", () => {
  it("loads SELECT-only SQL with required candidate set", () => {
    const sql = loadApproverProbeSql();
    expect(assertReadOnlySql(sql)).toEqual({ ok: true });
    for (const user of A1_APPROVER_CANDIDATES) {
      expect(sql).toContain(`'${user}'`);
    }
    expect(sql).toContain("'sedanurB'");
    for (const excluded of A1_EXCLUDED_SENAY_LINKED) {
      expect(sql).not.toMatch(new RegExp(`'${excluded}'`));
    }
    expect(sql.toUpperCase()).not.toMatch(/\b(INSERT|UPDATE|DELETE)\b/);
  });

  it("defaults to checklist mode without TARGET_ENV execute", () => {
    const plan = buildApproverProbePlan({});
    expect(plan.mode).toBe("CHECKLIST");
    expect(plan.canExecute).toBe(false);
    expect(plan.blockers).toContain("TARGET_ENV_REQUIRED");
    expect(plan.requiredColumns).toEqual([...A1_REQUIRED_OUTPUT_COLUMNS]);
  });

  it("requires explicit production confirmation before execute arming", () => {
    const plan = buildApproverProbePlan({
      TARGET_ENV: "production",
      A1_RO_PROBE_EXECUTE: "1",
      MEDISA_RO_DSN: "mysql://ro",
    });
    expect(plan.canExecute).toBe(false);
    expect(plan.blockers).toContain("CONFIRM_PRODUCTION_RO_REQUIRED");
  });

  it("redacts secret-looking keys", () => {
    expect(redactSecrets({ MEDISA_RO_DSN: "secret", ok: true }).MEDISA_RO_DSN).toBe("[REDACTED]");
  });
});

describe("personnel residual decision pack", () => {
  it("is no-apply and includes required decision columns", () => {
    const pack = loadPersonnelDecisionPack();
    expect(validatePersonnelDecisionPack(pack)).toEqual({ ok: true });
    expect(pack.production_mutation).toBe(0);
    expect(pack.classes.BUSINESS_DECISION_REQUIRED).toEqual([212]);
    expect(pack.classes.AUTO_RESOLVABLE_BY_CONFIRMED_TRUTH).toEqual([]);
    expect(pack.closed_do_not_reopen.A2_160).toMatch(/CLOSED/);
    for (const row of pack.rows) {
      for (const col of PERSONNEL_DECISION_REQUIRED_COLUMNS) {
        expect(row).toHaveProperty(col);
      }
    }
  });
});
