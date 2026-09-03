import { describe, expect, it } from "vitest";
import { resolve } from "node:path";
import { spawnSync } from "node:child_process";
import { readFileSync } from "node:fs";
import {
  SGK_SCOPE_ELIGIBLE_ROLES,
  SIRKET_SCOPE_ELIGIBLE_ROLES,
  WRITE_COMPANY_SCOPED_ROLES
} from "../../src/types/auth";

const root = process.cwd();
const ORG_SCOPE = readFileSync(resolve(root, "api/src/Scope/OrgScope.php"), "utf8");
const AUTH_MIDDLEWARE = readFileSync(resolve(root, "api/src/Auth/AuthMiddleware.php"), "utf8");
const LOGIN = readFileSync(resolve(root, "api/src/Auth/LoginController.php"), "utf8");
const YONETIM = readFileSync(resolve(root, "api/src/Controllers/YonetimController.php"), "utf8");
const FE_SCOPE = readFileSync(
  resolve(root, "src/features/yonetim/components/YonetimSubeScopeField.tsx"),
  "utf8"
);
const YONETIM_API = readFileSync(resolve(root, "src/api/yonetim.api.ts"), "utf8");

describe("canonical company + SGK user scope owners", () => {
  it("keeps company∪branch as visibility UNION and SGK as a separate OR payroll axis", () => {
    expect(ORG_SCOPE).toContain("const SIRKET_SCOPE_ELIGIBLE_ROLES = ['IK_SORUMLUSU', 'IK_PERSONELI', 'MUHASEBE'];");
    expect(ORG_SCOPE).toContain("const SGK_SCOPE_ELIGIBLE_ROLES = ['IK_SORUMLUSU', 'MUHASEBE'];");
    expect(ORG_SCOPE).toContain("count(self::allowedSubeIds($user)) === 0 && count(self::allowedSgkIsverenIds($user)) === 0");
    expect(ORG_SCOPE).toContain("'((' . implode(' AND ', $branchWhere) . ') OR ' . $sgkClause . ')'");
    expect(SIRKET_SCOPE_ELIGIBLE_ROLES).toEqual(["IK_SORUMLUSU", "IK_PERSONELI", "MUHASEBE"]);
    expect(SGK_SCOPE_ELIGIBLE_ROLES).toEqual(["IK_SORUMLUSU", "MUHASEBE"]);
    expect(WRITE_COMPANY_SCOPED_ROLES).toEqual(["IK_PERSONELI"]);
  });

  it("does not materialise company grants into İK session/request sube_ids", () => {
    expect(AUTH_MIDDLEWARE).toContain("OrgScope::isOrganizationGlobalRead(['rol' => $rol])");
    expect(LOGIN).toContain("$ikGlobalRead = OrgScope::isOrganizationGlobalRead(['rol' => $rol]);");
    expect(LOGIN).toContain("$subeIds = $ikGlobalRead");
  });

  it("lets MUHASEBE persist company or SGK grants without forcing user_subeler", () => {
    expect(YONETIM).toContain("Bu rol icin en az bir sube, sirket veya SGK kapsami zorunludur.");
    expect(YONETIM).toContain("assertVarsayilanSubeInEffectiveScope");
    expect(YONETIM_API).toContain("sgk_isveren_ids: readNumberArray(record.sgk_isveren_ids)");
    expect(FE_SCOPE).toContain('data-testid="yonetim-sirket-visibility-scope-field"');
    expect(FE_SCOPE).toContain('data-testid="yonetim-sgk-scope-field"');
  });

  it("passes the in-process org-hierarchy auth matrix including company/SGK cases", () => {
    const result = spawnSync("php", [resolve(root, "tests/php/OrgHierarchyAuthMatrixPhpTestRunner.php")], {
      encoding: "utf8",
      cwd: root
    });
    expect(result.status, result.stderr || result.stdout).toBe(0);
    for (const marker of [
      "IK_EMPTY_SCOPE=global_read",
      "IK_PERSONELI_COMPANY_GRANT_IGNORED_ON_READ",
      "MUHASEBE_BRANCH_ONLY=ALLOW_FILTER",
      "MUHASEBE_SGK_ONLY=PAYROLL_AXIS",
      "MUHASEBE_BRANCH_OR_SGK=UNION",
      "MUHASEBE_SGK_DOES_NOT_GRANT_BRANCH_ASSERT",
      "MANAGER_PERSONEL_BINDING_NOT_AUTHZ",
      "ORG_HIERARCHY_AUTH_MATRIX=PASS"
    ]) {
      expect(result.stdout).toContain(marker);
    }
  });
});
