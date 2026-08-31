import { execFileSync } from "node:child_process";
import { readFileSync } from "node:fs";
import { resolve } from "node:path";
import { describe, expect, it } from "vitest";

const runnerPath = resolve(process.cwd(), "tests/php/BranchlessDisKaynakListScopeTestRunner.php");
const orgScopePath = resolve(process.cwd(), "api/src/Scope/OrgScope.php");
const controllerPath = resolve(process.cwd(), "api/src/Controllers/PersonellerController.php");
const orgScopeSource = readFileSync(orgScopePath, "utf8");
const controllerSource = readFileSync(controllerPath, "utf8");

describe("branchless DIS_KAYNAK list scope (DB-backed)", () => {
  it("passes the full scope matrix against a real database", () => {
    const output = execFileSync("php", [runnerPath], { encoding: "utf8" });
    expect(output).toContain("BRANCHLESS_DIS_KAYNAK_LIST_SCOPE=PASS");
    for (const marker of [
      "unrestricted + branch1 sees branch1 staff + branchless DIS_KAYNAK",
      "unrestricted + branch2 sees branch2 staff (incl. bound DIS) + branchless DIS_KAYNAK",
      "unrestricted without active branch is unfiltered",
      "SUBE_YONETICISI scoped to branch1 does not see branchless DIS_KAYNAK",
      "MUHASEBE scoped to branch1 does not see branchless DIS_KAYNAK",
      // İK reads the whole organisation, so the branchless central pool is in
      // view for it exactly as it is for an unrestricted user.
      "IK_SORUMLUSU active branch view includes branchless DIS_KAYNAK",
      "IK_PERSONELI active branch view includes branchless DIS_KAYNAK",
      "IK_SORUMLUSU without active branch reads every branch",
      "branchless IC_PERSONEL never appears in a branch context",
      "branch-bound DIS_KAYNAK stays in its own branch",
      "search finds the branchless DIS_KAYNAK record inside a branch context",
      "calisan_kapsami=DIS_KAYNAK filter returns only the branchless record in branch1",
      "aktiflik filter still clamps the widened predicate",
      "pagination over the widened predicate is consistent with the total"
    ]) {
      expect(output).toContain(marker);
    }
  });

  it("keeps the widening in a single canonical scope owner", () => {
    expect(orgScopeSource).toContain("private static function withBranchlessDisKaynak");
    // The predicate text itself exists exactly once.
    const occurrences = orgScopeSource.match(/sube_id IS NULL AND/g) ?? [];
    expect(occurrences).toHaveLength(1);
    // No parallel implementation leaked into the controller.
    expect(controllerSource).not.toContain("withBranchlessDisKaynak");
    expect(controllerSource).not.toMatch(/sube_id IS NULL/);
  });

  it("gates the branch-scoped widening on OrgScope::isUnrestricted", () => {
    expect(orgScopeSource).toContain("$includeBranchless = self::isUnrestricted($user);");
    expect(orgScopeSource).toMatch(/\$includeBranchless\s*\n?\s*\?\s*self::withBranchlessDisKaynak/);
  });

  it("only widens DIS_KAYNAK and never references the column before the schema is ready", () => {
    expect(orgScopeSource).toContain("PersonelCalisanKapsamService::DIS_KAYNAK");
    expect(orgScopeSource).toContain("PersonelCalisanKapsamSchema::isReady($pdo)");
    expect(orgScopeSource).not.toContain("PersonelCalisanKapsamService::IC_PERSONEL");
    expect(orgScopeSource).not.toMatch(/calisan_kapsami\s*=\s*'IC_PERSONEL'/);
  });

  it("shares one predicate between the data, count and pagination queries", () => {
    // The controller must keep building every query from the same $whereSql.
    const whereSqlUses = controllerSource.match(/WHERE \$whereSql/g) ?? [];
    expect(whereSqlUses.length).toBeGreaterThanOrEqual(2);
    expect(controllerSource).toContain("$whereSql = implode(' AND ', $where);");
  });

  it("resolves visibility in SQL rather than merging results in PHP", () => {
    expect(controllerSource).not.toMatch(/array_merge\(\s*\$rows/);
    expect(orgScopeSource).not.toMatch(/UNION/i);
  });

  it("adds no migration", () => {
    const runnerSource = readFileSync(runnerPath, "utf8");
    expect(runnerSource).not.toMatch(/ALTER TABLE personeller/i);
  });
});
