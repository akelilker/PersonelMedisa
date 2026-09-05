import { readFileSync } from "node:fs";
import { resolve } from "node:path";
import { describe, expect, it } from "vitest";

const root = process.cwd();
const postcheckHelper = readFileSync(resolve(root, "scripts/ops/personel-lifecycle-exit-postcheck.mjs"), "utf8");
const deferredExitOps = readFileSync(resolve(root, "ops/personnel-lifecycle/deferred-exit-production-apply.mjs"), "utf8");

describe("MG personnel exit postcheck route parity (source contract)", () => {
  it("owns canonical exit surec reads on /surecler routes only", () => {
    expect(postcheckHelper).toContain("FORBIDDEN_EXIT_POSTCHECK_ROUTE = \"/personeller/{id}/surecler\"");
    expect(postcheckHelper).toContain("/surecler/${surecId}");
    expect(postcheckHelper).toContain("/surecler?personel_id=${personelId}");
    expect(postcheckHelper).not.toMatch(/\/personeller\/\$\{[^}]+\}\/surecler/);
    expect(postcheckHelper).toContain("POSTCHECK_ROUTE_READ_FAILED");
    expect(postcheckHelper).toContain("POSTCHECK_ROUTE_NOT_FOUND");
    expect(postcheckHelper).toContain("EXIT_SUREC_NOT_FOUND");
  });

  it("prefers apply entity_id as surec_id before list fallback", () => {
    expect(postcheckHelper).toContain("resolveSurecIdFromApplyResult");
    expect(postcheckHelper).toContain("PersonelIstenAyrilmaService");
    expect(postcheckHelper).toContain("fetchCanonicalExitSurec");
    expect(deferredExitOps).toContain("verifyPersonnelExitSurec");
    expect(deferredExitOps).toContain("resolveSurecIdFromApplyResult(applyRows, {");
    expect(deferredExitOps).toContain("mutationId: row.mutation_id");
    expect(deferredExitOps).toContain('owner: "PersonelHistoricalExitDateBackfillService"');
    expect(deferredExitOps).not.toMatch(/\/personeller\/\$\{[^}]+\}\/surecler/);
    expect(deferredExitOps).toContain("personel-lifecycle-exit-postcheck.mjs");
    expect(deferredExitOps).toContain("HISTORICAL_EXIT_DATE_BACKFILL");
    expect(deferredExitOps).not.toContain('operation_type: "PERSONEL_EXIT"');
    expect(deferredExitOps).toContain("--expected-sha");
    expect(deferredExitOps).toContain("parseExpectedSha");
    expect(deferredExitOps).toContain("evaluateResidualPreimage");
  });

  it("validates ISTEN_AYRILMA personel/date/aciklama with fail-closed business codes", () => {
    expect(postcheckHelper).toContain("EXIT_SUREC_PERSONEL_MISMATCH");
    expect(postcheckHelper).toContain("EXIT_SUREC_TURU_MISMATCH");
    expect(postcheckHelper).toContain("EXIT_SUREC_DATE_MISMATCH");
    expect(postcheckHelper).toContain("EXIT_SUREC_ACIKLAMA_MISMATCH");
    expect(postcheckHelper).toContain("resolveExitSurecFromList");
  });
});
