import { readFileSync } from "node:fs";
import { resolve } from "node:path";
import { describe, expect, it } from "vitest";

const root = process.cwd();
const read = (rel: string) => readFileSync(resolve(root, rel), "utf8");

describe("A1 ADR-aligned formal SGK scope + BOLUM optional user_subeler", () => {
  it("SgkKararPaketiAuthz resolves formal branch scope from DB user_subeler with PDO", () => {
    const authz = read("api/src/Services/Payroll/SgkKararPaketiAuthz.php");
    expect(authz).toContain("function resolveExplicitBranchScope(PDO $pdo, array $actor)");
    expect(authz).toContain("FROM user_subeler WHERE user_id = :user_id");
    expect(authz).toContain("function assertSubeScope(PDO $pdo, array $actor, $subeId)");
    expect(authz).toContain("self::resolveExplicitBranchScope($pdo, $actor)");
    expect(authz).toContain("SGK_ACTOR_SCOPE_NOT_READY");
    expect(authz).toContain("SGK_ACTOR_SCOPE_FORBIDDEN");
    // Must not treat visibility/session sube_ids as formal truth.
    expect(authz).not.toMatch(
      /function assertSubeScope\(array \$actor, \$subeId\)[\s\S]*\$actor\['sube_ids'\]/,
    );
    // No OrgScope unrestricted / company expansion bypass for SGK.
    expect(authz).not.toContain("isUnrestricted");
    expect(authz).not.toContain("resolveSubeIdsForSirketIds");
    expect(authz).toContain("user_subeler");
  });

  it("politika write service passes PDO into assertSubeScope", () => {
    const write = read("api/src/Services/Payroll/SgkSirketPolitikaWriteService.php");
    expect(write).toContain("SgkKararPaketiAuthz::assertSubeScope($pdo, $actor, $subeId)");
    expect(write).toContain(
      "SgkKararPaketiAuthz::assertSubeScope($pdo, $actor, (int) ($surum['sube_id'] ?? 0))",
    );
    expect(write).not.toMatch(/assertSubeScope\(\$actor,/);
  });

  it("formalActorReadiness uses DB explicit branch scope and accept prepare or approve", () => {
    const authz = read("api/src/Services/Payroll/SgkKararPaketiAuthz.php");
    expect(authz).toMatch(
      /function formalActorReadiness[\s\S]*resolveExplicitBranchScope\(\$pdo, \$actor\)/,
    );
    expect(authz).toMatch(
      /function formalActorReadiness[\s\S]*PERM_PREPARE[\s\S]*PERM_APPROVE[\s\S]*SGK_DUAL_CONTROL_FORBIDDEN/,
    );
    expect(authz).not.toMatch(
      /function formalActorReadiness[\s\S]*assertPermission\(\$actor, self::PERM_PREPARE/,
    );
  });

  it("OrgScope BOLUM optional user_subeler does not confine via assertActiveSubeNarrow", () => {
    const org = read("api/src/Scope/OrgScope.php");
    expect(org).toContain("function assertActiveSubeNarrow");
    expect(org).toContain("BOLUM_ASSIGNMENT_ROLES");
    expect(org).toMatch(
      /function assertActiveSubeNarrow[\s\S]*BOLUM_ASSIGNMENT_ROLES[\s\S]*return;/,
    );
    // Active-sube header/query narrow still present before the BOLUM early return.
    expect(org).toMatch(
      /function assertActiveSubeNarrow[\s\S]*x-active-sube-id[\s\S]*BOLUM_ASSIGNMENT_ROLES/,
    );
  });

  it("AuthMiddleware still separates visibility sube_ids from explicit_sube_ids for IK", () => {
    const auth = read("api/src/Auth/AuthMiddleware.php");
    expect(auth).toContain("isOrganizationGlobalRead");
    expect(auth).toContain("'explicit_sube_ids' => $explicitSubeIds");
    expect(auth).toContain("'sube_ids' => $visibilitySubeIds");
  });
});
