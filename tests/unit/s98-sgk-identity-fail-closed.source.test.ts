import { readFileSync } from "node:fs";
import { resolve } from "node:path";
import { describe, expect, it } from "vitest";

function read(path: string): string {
  return readFileSync(resolve(path), "utf8");
}

describe("S98 SGK dual-control identity fail-closed", () => {
  it("authz service is fail-closed for missing actor identity/schema/scope", () => {
    const authz = read("api/src/Services/Payroll/SgkKararPaketiAuthz.php");
    expect(authz).toContain("SGK_ACTOR_IDENTITY_LINK_REQUIRED");
    expect(authz).toContain("SGK_PREPARER_ACTOR_IDENTITY_REQUIRED");
    expect(authz).toContain("SGK_APPROVER_ACTOR_IDENTITY_REQUIRED");
    expect(authz).toContain("SGK_ACTOR_IDENTITY_SCHEMA_REQUIRED");
    expect(authz).toContain("SGK_ACTOR_IDENTITY_NOT_FOUND");
    expect(authz).toContain("SGK_ACTOR_IDENTITY_NOT_VERIFIED");
    expect(authz).toContain("SGK_SAME_ACTOR_IDENTITY_FORBIDDEN");
    expect(authz).toContain("SGK_ACTOR_IDENTITY_CONFLICT");
    expect(authz).toContain("SGK_ACTOR_SCOPE_NOT_READY");
    expect(authz).toContain("SGK_ACTOR_IDENTITY_INVALID");
    expect(authz).toContain("assertActorIdentitySchemaRequired");
    expect(authz).toContain("assertActorIdentityLinkedAndVerified");
    expect(authz).toContain("actor_identity_id");
    expect(authz).not.toContain("SGK_ACTOR_PERSONEL_LINK_REQUIRED");
    expect(authz).not.toContain("SGK_PREPARER_PERSONEL_LINK_REQUIRED");
    expect(authz).not.toContain("SGK_ACTOR_PERSONEL_SCHEMA_REQUIRED");
    expect(authz).not.toMatch(/static\s+\$cached/);
    // Empty sube_ids must deny (no global bypass)
    expect(authz).toMatch(/subeIds === \[\][\s\S]*SGK_ACTOR_SCOPE_NOT_READY/);
    // Missing schema must deny same-person path
    expect(authz).toMatch(/!self::actorIdentitySchemaSupported\(\$pdo\)[\s\S]*SGK_ACTOR_IDENTITY_SCHEMA_REQUIRED/);
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
    expect(controller).toContain("SGK_ACTOR_IDENTITY_LINK_REQUIRED");
    expect(controller).toContain("SGK_PREPARER_ACTOR_IDENTITY_REQUIRED");
    expect(controller).toContain("SGK_ACTOR_IDENTITY_SCHEMA_REQUIRED");
    expect(controller).toContain("SGK_ACTOR_IDENTITY_NOT_VERIFIED");
    expect(controller).toContain("SGK_SAME_ACTOR_IDENTITY_FORBIDDEN");
    expect(controller).toContain("SGK_ACTOR_SCOPE_NOT_READY");
    expect(controller).toContain("SGK_ACTOR_IDENTITY_INVALID");
    expect(controller).not.toContain("SGK_ACTOR_PERSONEL_LINK_REQUIRED");
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

  it("migration 048 keeps ENUM superset and actor_identities registry", () => {
    const mig = read("api/migrations/048_sgk_dual_control_actor_roles.sql");
    expect(mig).toContain("GENEL_YONETICI");
    expect(mig).toContain("MUHASEBE");
    expect(mig).toContain("AUTH_SMOKE_READONLY");
    expect(mig).toContain("IK_BORDRO");
    expect(mig).toContain("SGK_KARAR_ONAY_YETKILISI");
    expect(mig).toContain("CREATE TABLE IF NOT EXISTS actor_identities");
    expect(mig).toContain("actor_identity_id INT UNSIGNED NULL");
    expect(mig).toContain("uq_users_actor_identity_id");
    expect(mig).toContain("fk_users_actor_identity");
    expect(mig).toContain("PENDING");
    expect(mig).toContain("VERIFIED");
    expect(mig).toContain("REVOKED");
    expect(mig).toContain("ON DELETE SET NULL");
    expect(mig).not.toContain("uq_users_personel_id");
    expect(mig).not.toContain("fk_users_personel");
    expect(mig).not.toMatch(/ADD COLUMN personel_id/i);
    expect(mig).not.toMatch(/ON DELETE CASCADE/i);
    // No production actor seed / real names
    expect(mig).not.toMatch(/INSERT\s+INTO\s+actor_identities/i);
    expect(mig).not.toMatch(/INSERT\s+INTO\s+users/i);
  });

  it("auth session exposes durum + actor_identity_id for fail-closed SGK actor checks", () => {
    const auth = read("api/src/Auth/AuthMiddleware.php");
    expect(auth).toContain("'durum' => (string) ($row['durum'] ?? '')");
    expect(auth).toContain("self::$user['actor_identity_id']");
    expect(auth).toContain("self::$user['actor_identity_status']");
    // S3B may expose users.personel_id as identity binding; SGK actor bridge remains separate.
    expect(auth).not.toMatch(/static\s+\$sql\s*=\s*null/);
  });

  it("write controllers pass session user not request actor override fields", () => {
    const controller = read("api/src/Controllers/SgkKatalogHazirlikController.php");
    expect(controller).toContain("AuthMiddleware::authenticate($request, true)");
    expect(controller).toContain("$result = SgkKatalogWriteService::import($pdo, $user, $body)");
    expect(controller).toContain("$result = SgkKatalogWriteService::approve($pdo, $user, $body)");
    expect(controller).toContain("$result = SgkSirketPolitikaWriteService::approve($pdo, $user, self::jsonBody($request))");
    expect(controller).not.toMatch(/\$body\[['"]actor_id['"]\]/);
    expect(controller).not.toMatch(/\$body\[['"]actor_identity_id['"]\]/);
    expect(controller).not.toMatch(/actor_id.*\$body|\$body.*as.*actor/);
  });

  it("permission matrix matches dedicated preparer/approver contract", () => {
    const perms = read("api/src/Auth/RolePermissions.php");
    expect(perms).toMatch(/'IK_SORUMLUSU'\s*=>\s*\[[\s\S]*?'sgk_karar_paketi\.prepare'/);
    expect(perms).toMatch(/'GENEL_YONETICI'\s*=>\s*\[[\s\S]*?'sgk_karar_paketi\.approve'/);
    expect(perms).toMatch(/'BOLUM_YONETICISI'\s*=>\s*\[[\s\S]*?'sgk_karar_paketi\.approve'/);
    expect(perms).toContain("'IK_BORDRO' => 'IK_SORUMLUSU'");
    expect(perms).not.toMatch(/'SGK_KARAR_ONAY_YETKILISI'\s*=>\s*\[/);
    const ikStart = perms.indexOf("'IK_SORUMLUSU' => [");
    const ikEnd = perms.indexOf("'SISTEM_YONETICISI' => [");
    expect(ikStart).toBeGreaterThan(-1);
    expect(ikEnd).toBeGreaterThan(ikStart);
    const ikBlock = perms.slice(ikStart, ikEnd);
    expect(ikBlock).toContain("personeller.ucret.view");
    expect(ikBlock).toContain("sgk_karar_paketi.prepare");
    expect(ikBlock).not.toContain("sgk_karar_paketi.approve");
    const gyBlock = perms.slice(
      perms.indexOf("'GENEL_YONETICI' => ["),
      perms.indexOf("'BOLUM_YONETICISI' => [")
    );
    expect(gyBlock).toContain("sgk_karar_paketi.approve");
    expect(gyBlock).toContain("sgk_karar_paketi.prepare");
    const bolumBlock = perms.slice(
      perms.indexOf("'BOLUM_YONETICISI' => ["),
      perms.indexOf("'MUHASEBE' => [")
    );
    expect(bolumBlock).toContain("sgk_karar_paketi.approve");
    expect(bolumBlock).not.toContain("sgk_karar_paketi.prepare");
    expect(bolumBlock).not.toContain("legal_hold.manage");
    expect(bolumBlock).not.toContain("retention.destruction.approve");
    expect(bolumBlock).not.toContain("genel_yonetici_onayi.approve");
    expect(bolumBlock).not.toContain("bordro_kesinlestirme.approve");
  });
});
