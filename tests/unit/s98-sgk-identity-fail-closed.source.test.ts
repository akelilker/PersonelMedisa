import { readFileSync } from "node:fs";
import { resolve } from "node:path";
import { describe, expect, it } from "vitest";

function read(path: string): string {
  return readFileSync(resolve(path), "utf8");
}

describe("S98 SGK dual-control identity fail-closed", () => {
  it("authz service is fail-closed for missing personel link/schema/scope", () => {
    const authz = read("api/src/Services/Payroll/SgkKararPaketiAuthz.php");
    expect(authz).toContain("SGK_ACTOR_PERSONEL_LINK_REQUIRED");
    expect(authz).toContain("SGK_PREPARER_PERSONEL_LINK_REQUIRED");
    expect(authz).toContain("SGK_ACTOR_PERSONEL_SCHEMA_REQUIRED");
    expect(authz).toContain("SGK_ACTOR_SCOPE_NOT_READY");
    expect(authz).toContain("SGK_ACTOR_IDENTITY_INVALID");
    expect(authz).toContain("assertPersonelSchemaRequired");
    expect(authz).toContain("assertActorPersonelLinked");
    expect(authz).not.toMatch(/static\s+\$cached/);
    // Empty sube_ids must deny (no global bypass)
    expect(authz).toMatch(/subeIds === \[\][\s\S]*SGK_ACTOR_SCOPE_NOT_READY/);
    // Missing schema must deny same-person path
    expect(authz).toMatch(/!self::personelLinkSupported\(\$pdo\)[\s\S]*SGK_ACTOR_PERSONEL_SCHEMA_REQUIRED/);
  });

  it("write services pass PDO into prepare/approve authz", () => {
    for (const path of [
      "api/src/Services/Payroll/SgkKatalogWriteService.php",
      "api/src/Services/Payroll/SgkSirketPolitikaWriteService.php",
      "api/src/Services/Payroll/SgkSurecEslemeWriteService.php",
    ]) {
      const src = read(path);
      expect(src, path).toContain("SgkKararPaketiAuthz::assertPrepare($pdo, $actor)");
    }
    expect(read("api/src/Services/Payroll/SgkKatalogWriteService.php")).toContain(
      "SgkKararPaketiAuthz::assertApprove($pdo, $actor)",
    );
    expect(read("api/src/Services/Payroll/SgkSirketPolitikaWriteService.php")).toContain(
      "SgkKararPaketiAuthz::assertApprove($pdo, $actor)",
    );
  });

  it("controller maps new identity error codes without leaking internals", () => {
    const controller = read("api/src/Controllers/SgkKatalogHazirlikController.php");
    expect(controller).toContain("SGK_ACTOR_PERSONEL_LINK_REQUIRED");
    expect(controller).toContain("SGK_PREPARER_PERSONEL_LINK_REQUIRED");
    expect(controller).toContain("SGK_ACTOR_PERSONEL_SCHEMA_REQUIRED");
    expect(controller).toContain("SGK_ACTOR_SCOPE_NOT_READY");
    expect(controller).toContain("SGK_ACTOR_IDENTITY_INVALID");
    expect(controller).not.toContain("stack trace");
    expect(controller).not.toContain("SQLSTATE");
  });

  it("frontend approve visibility uses sgk_karar_paketi.approve not GENEL_YONETICI hardcode", () => {
    const panel = read("src/features/raporlar/components/SgkKatalogHazirlikPanel.tsx");
    expect(panel).toContain('hasPermission("sgk_karar_paketi.prepare")');
    expect(panel).toContain('hasPermission("sgk_karar_paketi.approve")');
    expect(panel).toContain("canPrepare");
    expect(panel).toContain("canApprove");
    expect(panel).toContain('data-testid="sgk-katalog-approve"');
    expect(panel).toContain("!approveAktif || !canApprove");
    expect(panel).not.toContain("rol === 'GENEL_YONETICI'");
    expect(panel).not.toContain('rol === "GENEL_YONETICI"');
  });

  it("migration 048 keeps ENUM superset and unsigned personel_id", () => {
    const mig = read("api/migrations/048_sgk_dual_control_actor_roles.sql");
    expect(mig).toContain("GENEL_YONETICI");
    expect(mig).toContain("MUHASEBE");
    expect(mig).toContain("AUTH_SMOKE_READONLY");
    expect(mig).toContain("IK_BORDRO");
    expect(mig).toContain("SGK_KARAR_ONAY_YETKILISI");
    expect(mig).toContain("personel_id INT UNSIGNED NULL");
    expect(mig).toContain("uq_users_personel_id");
    expect(mig).toContain("fk_users_personel");
    expect(mig).not.toMatch(/ON DELETE CASCADE/i);
    const initial = read("api/migrations/001_initial_schema.sql");
    expect(initial).toMatch(/CREATE TABLE IF NOT EXISTS personeller[\s\S]*?id INT UNSIGNED NOT NULL AUTO_INCREMENT/);
  });

  it("auth session exposes durum + personel_id for fail-closed SGK actor checks", () => {
    const auth = read("api/src/Auth/AuthMiddleware.php");
    expect(auth).toContain("'durum' => (string) ($row['durum'] ?? '')");
    expect(auth).toContain("self::$user['personel_id']");
    expect(auth).not.toMatch(/static\s+\$sql\s*=\s*null/);
  });

  it("write controllers pass session user not request actor override fields", () => {
    const controller = read("api/src/Controllers/SgkKatalogHazirlikController.php");
    expect(controller).toContain("AuthMiddleware::authenticate($request, true)");
    expect(controller).toContain("$result = SgkKatalogWriteService::import($pdo, $user, $body)");
    expect(controller).toContain("$result = SgkKatalogWriteService::approve($pdo, $user, $body)");
    expect(controller).toContain("$result = SgkSirketPolitikaWriteService::approve($pdo, $user, self::jsonBody($request))");
    expect(controller).not.toMatch(/\$body\[['"]actor_id['"]\]/);
    expect(controller).not.toMatch(/\$body\[['"]personel_id['"]\]/);
    expect(controller).not.toMatch(/actor_id.*\$body|\$body.*as.*actor/);
  });

  it("permission matrix matches dedicated preparer/approver contract", () => {
    const perms = read("api/src/Auth/RolePermissions.php");
    expect(perms).toMatch(/'IK_BORDRO'\s*=>\s*\[[\s\S]*?'sgk_karar_paketi\.prepare'/);
    expect(perms).toMatch(/'SGK_KARAR_ONAY_YETKILISI'\s*=>\s*\[[\s\S]*?'sgk_karar_paketi\.approve'/);
    const ikBlock = perms.slice(perms.indexOf("'IK_BORDRO'"), perms.indexOf("'SGK_KARAR_ONAY_YETKILISI'"));
    expect(ikBlock).toContain("personeller.ucret.view");
    expect(ikBlock).not.toContain("sgk_karar_paketi.approve");
    const apprBlock = perms.slice(perms.indexOf("'SGK_KARAR_ONAY_YETKILISI'"), perms.indexOf("];", perms.indexOf("'SGK_KARAR_ONAY_YETKILISI'")) + 2);
    expect(apprBlock).not.toContain("sgk_karar_paketi.prepare");
    expect(apprBlock).not.toContain("personeller.ucret.view");
  });
});
