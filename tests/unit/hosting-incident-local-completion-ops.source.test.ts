import { describe, expect, it } from "vitest";
import { readFileSync } from "node:fs";
import { resolve } from "node:path";
import {
  A1_APPROVER_DISPLAY_NAME,
  A1_APPROVER_INTENDED_ROLE_MODEL,
  A1_APPROVER_LOCAL_PERSONEL_ID,
  A1_APPROVER_LOCAL_USER_ID,
  A1_APPROVER_PROBE_USERNAMES,
  A1_EXCLUDED_SENAY_LINKED,
  A1_PREPARER_USERNAME,
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
  it("loads SELECT-only SQL locked to Sinem + sedanurB keys", () => {
    const sql = loadApproverProbeSql();
    expect(assertReadOnlySql(sql)).toEqual({ ok: true });
    expect(sql).toContain(`'${A1_PREPARER_USERNAME}'`);
    for (const user of A1_APPROVER_PROBE_USERNAMES) {
      expect(sql).toContain(`'${user}'`);
    }
    expect(sql).toContain(String(A1_APPROVER_LOCAL_USER_ID));
    expect(sql).toContain(String(A1_APPROVER_LOCAL_PERSONEL_ID));
    expect(sql).toContain("Sinem");
    expect(sql).toContain("Hamaloğlu");
    for (const excluded of A1_EXCLUDED_SENAY_LINKED) {
      expect(sql).not.toMatch(new RegExp(`'${excluded}'`));
    }
    expect(sql).not.toContain("'343'");
    expect(sql).not.toContain("'220'");
    expect(sql.toUpperCase()).not.toMatch(/\b(INSERT|UPDATE|DELETE)\b/);
  });

  it("defaults to checklist mode without TARGET_ENV execute", () => {
    const plan = buildApproverProbePlan({});
    expect(plan.mode).toBe("CHECKLIST");
    expect(plan.canExecute).toBe(false);
    expect(plan.blockers).toContain("TARGET_ENV_REQUIRED");
    expect(plan.approverDisplayName).toBe(A1_APPROVER_DISPLAY_NAME);
    expect(plan.intendedRoleModel).toBe(A1_APPROVER_INTENDED_ROLE_MODEL);
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
  it("is no-apply with loc5 truth locked and cross-company defer retained", () => {
    const pack = loadPersonnelDecisionPack();
    expect(validatePersonnelDecisionPack(pack)).toEqual({ ok: true });
    expect(pack.production_mutation).toBe(0);
    expect(pack.classes.BUSINESS_DECISION_REQUIRED).toEqual([]);
    expect(pack.classes.AUTO_RESOLVABLE_BY_CONFIRMED_TRUTH).toEqual([
      200, 201, 203, 204, 205, 206, 209, 210, 212, 217,
    ]);
    expect(pack.classes.CROSS_COMPANY_SEMANTICALLY_VALID).toEqual([120, 158, 219]);
    expect(pack.classes.NAME_CORRECTION_REQUIRED).toEqual([203]);
    expect(pack.closed_do_not_reopen.A2_160).toMatch(/CLOSED/);
    expect(pack.closed_do_not_reopen.A2_211).toMatch(/CLOSED/);
    for (const row of pack.rows) {
      for (const col of PERSONNEL_DECISION_REQUIRED_COLUMNS) {
        expect(row).toHaveProperty(col);
      }
    }
  });
});

describe("no-apply location / name / branch-manager plans", () => {
  it("pins loc5 preimage targets without apply", () => {
    const plan = JSON.parse(
      readFileSync(
        resolve(process.cwd(), "ops/organization-mapping/fabrika-karabuk-loc5-no-apply-preimage-plan.json"),
        "utf8",
      ),
    );
    expect(plan.production_mutation).toBe(0);
    expect(plan.apply).toBe("NONE");
    expect(plan.target_calisma_lokasyonu_id).toBe(5);
    expect(plan.targets.map((t: { PERSONEL_ID: number }) => t.PERSONEL_ID)).toEqual([
      200, 201, 203, 204, 205, 206, 209, 210, 212, 217,
    ]);
    expect(plan.closed_do_not_reopen.map((t: { PERSONEL_ID: number }) => t.PERSONEL_ID)).toEqual([
      160, 211,
    ]);
  });

  it("pins personel 203 name correction to ad/soyad only", () => {
    const plan = JSON.parse(
      readFileSync(
        resolve(process.cwd(), "ops/organization-mapping/personel-203-name-correction-no-apply.json"),
        "utf8",
      ),
    );
    expect(plan.production_mutation).toBe(0);
    expect(plan.PERSONEL_ID).toBe(203);
    expect(plan.correction).toEqual({ ad: "Muhammed", soyad: "Mahmud" });
    expect(plan.canonical_schema.fields).toEqual(["ad", "soyad"]);
  });

  it("locks branch-manager assignment model as already supported / no write", () => {
    const plan = JSON.parse(
      readFileSync(
        resolve(process.cwd(), "ops/organization-mapping/branch-manager-assignment-no-apply-plan.json"),
        "utf8",
      ),
    );
    expect(plan.production_mutation).toBe(0);
    expect(plan.architecture.USER_SUBELER_SEMANTIC).toBe("ACCESS_SCOPE_ONLY");
    expect(plan.architecture.BRANCH_MANAGER_ASSIGNMENT_OWNER).toContain("sube_sorumlu_yoneticiler");
    expect(plan.architecture.TABLE_MODEL).toContain("sube_sorumlu_yoneticiler");
    expect(plan.architecture.MUST_NOT_ENCODE_MANAGERS_AS).toBe("user_subeler");
    expect(plan.architecture.BRANCH_MANAGER_TECHNICAL_STATUS).toBe(
      "TECHNICAL_GAP_LOCAL_FIXABLE"
    );
    expect(plan.architecture.TECHNICAL_FIX_REQUIRED).toBe(true);
    expect(plan.architecture.TECHNICAL_FIX_PR).toBe(278);
    expect(plan.architecture.MANAGER_ASSIGNMENT_GRANTS_ACCESS).toBe(false);
    expect(plan.architecture.MULTI_BRANCH_SUPPORTED).toBe(true);
    expect(plan.architecture.ZERO_MANAGER_SUPPORTED).toBe(true);
    expect(plan.architecture.SAME_BRANCH_REQUIRED).toBe(false);
    expect(plan.a1_formal_sgk_scope.axis).toBe("user_subeler");
    expect(plan.future_apply_steps.join("\n")).toContain("sube_sorumlu_yoneticiler");
    expect(plan.future_apply_steps.join("\n")).not.toContain(
      "Write only user_subeler grants for managed branches"
    );
    const kayseri = plan.medisa_map.find((r: { sube_id: number }) => r.sube_id === 4);
    expect(kayseri.surname).toBe("UNKNOWN_DO_NOT_GUESS");
    expect(kayseri.identity_status).toBe("BUSINESS_IDENTITY_DECISION_REQUIRED");
  });
});
