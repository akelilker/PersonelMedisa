import { readFileSync } from "node:fs";
import { describe, expect, it } from "vitest";

const service = readFileSync("api/src/Services/Auth/ActorIdentityService.php", "utf8");
const authz = readFileSync("api/src/Services/Payroll/SgkKararPaketiAuthz.php", "utf8");
const controller = readFileSync("api/src/Controllers/YonetimController.php", "utf8");
const router = readFileSync("api/src/Router.php", "utf8");
const migration = readFileSync("api/migrations/068_sgk_actor_identity_lifecycle_audit.sql", "utf8");
const endpoints = readFileSync("src/api/endpoints.ts", "utf8");
const api = readFileSync("src/api/yonetim.api.ts", "utf8");

describe("formal SGK actor identity lifecycle", () => {
  it("has one canonical service and authenticated management routes", () => {
    expect(service).toContain("final class ActorIdentityService");
    expect(controller).toContain("AuthMiddleware::authenticate($request, true)");
    expect(controller).toContain("ActorIdentityService::create");
    expect(controller).toContain("ActorIdentityService::verify");
    expect(controller).toContain("ActorIdentityService::bind");
    expect(router).toContain("'/yonetim/actor-identities' && $method === 'POST'");
    expect(router).toContain("actor-identities/(\\d+)/verify");
    expect(router).toContain("kullanicilar/(\\d+)/actor-identity");
  });

  it("uses the narrow existing management permission", () => {
    expect(service).toContain("yonetim-paneli.manage");
    expect(service).not.toContain("sgk_karar_paketi.approve");
    expect(service).not.toContain("GENEL_YONETICI");
  });

  it("supports both optional-personel and personel-linked formal identities", () => {
    expect(service).toContain("personel_id");
    expect(service).toContain("aktif_durum");
    expect(service).toContain("self::formalDisplayName($user['ad_soyad'] ?? '')");
    expect(service).toContain("'USER-' . (int) $userId");
    expect(service).toContain("'PERSONEL-' . (int) $personelId");
    expect(service).toContain("$personelId !== null ? self::loadActivePersonel");
    expect(service).not.toContain("ACTOR_PERSONEL_LINK_REQUIRED");
    expect(service).toContain("ACTOR_GENERIC_USER_FORBIDDEN");
    expect(service).toContain("ACTOR_DISPLAY_NAME_REQUIRED");
    expect(service).toContain("assertFormalUsername");
    expect(authz).toContain("isFormalUsername");
  });

  it("uses the schema lifecycle without inventing status values", () => {
    expect(migration).toContain("actor_identity_audits");
    expect(service).toContain("'PENDING'");
    expect(service).toContain("'VERIFIED'");
    expect(service).toContain("'HUMAN_CONFIRMED'");
    expect(service).toContain("'PERSONEL_LINKED'");
    expect(service).toContain("'REVOKED'");
    expect(service).toContain("ACTOR_IDENTITY_REVOKED");
  });

  it("validates active branch scope and keeps it in user_subeler", () => {
    expect(service).toContain("user_subeler");
    expect(service).toContain("LEFT JOIN subeler");
    expect(service).toContain("s.durum");
    expect(service).toContain("ACTOR_SCOPE_REQUIRED");
    expect(service).toContain("ACTOR_SCOPE_INVALID");
    expect(authz).toContain("assertActiveActorScope");
    expect(authz).toContain("formalActorReadiness");
  });

  it("makes create, verify, and bind transaction-safe and auditable", () => {
    expect(service).toContain("$pdo->beginTransaction()");
    expect(service).toContain("$pdo->commit()");
    expect(service).toContain("$pdo->rollBack()");
    expect(service).toContain("writeAudit");
    expect(service).toContain("'CREATE'");
    expect(service).toContain("'VERIFY'");
    expect(service).toContain("'BIND'");
    expect(migration).toContain("changed_by_user_id");
  });

  it("preserves idempotency and cross-person binding protection", () => {
    expect(service).toContain("ACTOR_IDENTITY_ALREADY_EXISTS");
    expect(service).toContain("ACTOR_IDENTITY_ALREADY_BOUND");
    expect(service).toContain("ACTOR_PERSONEL_MISMATCH");
    expect(service).toContain("ACTOR_IDENTITY_OWNER_MISMATCH");
    expect(service).toContain("intendedOwnerUserId");
    expect(service).toContain("action = 'CREATE'");
    expect(service).toContain("USER_ACTOR_IDENTITY_CONFLICT");
    expect(service).toContain("$currentIdentityId !== $identityId");
  });

  it("forbids self-verification before and after binding", () => {
    expect(service).toContain("$intendedOwnerId = self::intendedOwnerUserId");
    expect(service).toContain("$boundUserId === $adminId");
    expect(service).toContain("$intendedOwnerId === $adminId");
    expect(service).toContain("ACTOR_IDENTITY_SELF_VERIFY_FORBIDDEN");
  });

  it("exposes a minimal readback contract and frontend owner", () => {
    expect(service).toContain("'actor_identity_id'");
    expect(service).toContain("'actor_status'");
    expect(service).toContain("'branch_scope'");
    expect(service).toContain("'ready'");
    expect(service).toContain("u.username, u.rol, u.durum");
    expect(service).toContain("'rol' => (string) ($row['rol'] ?? '')");
    expect(endpoints).toContain("actorIdentityDetail");
    expect(endpoints).toContain("actorIdentityVerify");
    expect(endpoints).toContain("kullaniciActorIdentity");
    expect(api).toContain("createYonetimActorIdentity");
    expect(api).toContain("verifyYonetimActorIdentity");
    expect(api).toContain("bindYonetimActorIdentity");
    expect(api).toContain("fetchYonetimActorIdentity");
  });

  it("keeps SGK authorization as the final readiness consumer", () => {
    expect(authz).toContain("assertActorIdentityLinkedAndVerified");
    expect(authz).toContain("SGK_ACTOR_IDENTITY_NOT_VERIFIED");
    expect(authz).toContain("SGK_ACTOR_IDENTITY_CONFLICT");
    expect(service).toContain("SgkKararPaketiAuthz::formalActorReadiness");
    expect(service).not.toContain("INSERT INTO sgk_");
    expect(service).not.toContain("UPDATE sgk_");
  });

  it("does not contain the current production target as a special case", () => {
    expect(service).not.toContain("Sedanur");
    expect(service).not.toContain("sedanurB");
    expect(service).not.toContain("PERSONNEL_ID = 160");
  });
});
