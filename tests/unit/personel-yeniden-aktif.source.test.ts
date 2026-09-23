import { readFileSync } from "node:fs";
import { resolve } from "node:path";
import { describe, expect, it } from "vitest";
import { hasRolePermission } from "../../src/lib/authorization/role-permissions";

const root = process.cwd();

function read(relativePath: string): string {
  return readFileSync(resolve(root, relativePath), "utf8");
}

const SERVICE_PATH = "api/src/Services/Personel/PersonelYenidenAktifService.php";
const CONTROLLER_PATH = "api/src/Controllers/PersonellerController.php";
const GATE_PATH = "api/src/Services/Retention/PersonelArchiveGate.php";

/**
 * The service is PHP, so negative "must not do X" contracts are checked against
 * code only: docblock prose (which explains why the gate is respected) must not
 * be able to satisfy or break a structural assertion.
 */
function phpCodeOnly(source: string): string {
  return source.replace(/\/\*[\s\S]*?\*\//g, "").replace(/\/\/[^\n]*/g, "");
}

describe("personel yeniden aktif (PASIF -> AKTIF canonical lifecycle) source contract", () => {
  it("owns the transition through PersonelIstenAyrilmaService's symmetric counterpart", () => {
    const service = read(SERVICE_PATH);
    // The transition itself.
    expect(service).toContain("aktif_durum = 'AKTIF'");
    expect(service).toContain("WHERE id = :id AND aktif_durum = 'PASIF'");
    // Exit process is cancelled, never deleted.
    expect(service).toContain("UPDATE surecler SET state = 'IPTAL'");
    expect(service).not.toContain("DELETE FROM surecler");
    // Lifecycle manifests minted through the canonical INSERT-only owner.
    expect(service).toContain("ArchiveManifestService::createPersonelLifecycleManifests");
    // Durable evidence through the canonical append-only audit writer.
    expect(service).toContain("OrganizasyonAuditWriter::recordPersonelOrganizasyonDegisikligi");
    expect(service).toContain("assertPersonelOrganizasyonReady");
    expect(service).toContain("YENIDEN_ISE_ALMA");
  });

  it("is fail-closed on fixtures, legal hold and the exact-one open exit rule", () => {
    const service = read(SERVICE_PATH);
    expect(service).toContain("TestFixturePersonelClassificationService::isOperationallyHidden");
    expect(service).toContain("RetentionPolicyService::hasActiveLegalHold");
    expect(service).toContain("ERROR_EXIT_SUREC_MISSING");
    expect(service).toContain("ERROR_EXIT_SUREC_NOT_UNIQUE");
    expect(service).toContain("ERROR_CONFLICT");
    // Transaction ownership is mandatory: no silent autocommit path.
    expect(service).toContain("$pdo->inTransaction()");
  });

  it("performs the DIS_KAYNAK -> IC_PERSONEL transition through the canonical kapsam owner", () => {
    const service = read(SERVICE_PATH);
    expect(service).toContain("PersonelCalisanKapsamService::assertInternalIdentityComplete");
    expect(service).toContain("PersonelCalisanKapsamService::DIS_KAYNAK");
    expect(service).toContain("PersonelCalisanKapsamService::IC_PERSONEL");
    // Target branch / SGK employer consistency is verified, never re-implemented.
    expect(service).toContain("PersonelSgkCompanyConsistency::evaluateForKapsam");
    expect(service).toContain("PersonelSgkCompanyConsistency::assertRequiredForActiveIc");
    // Each axis stays on its canonical owner instead of writing around it.
    expect(service).toContain("PersonelKaliciSubeDegisikligiService::applyInTransaction");
    expect(service).toContain("PersonelOrganizasyonDegisikligiService::applyInTransaction");
    // No hardcoded live ids: the target branch/SGK employer come from the request.
    expect(service).not.toMatch(/Karab[üu]k/i);
    expect(service).not.toMatch(/\b(217|383)\b/);

    // Telefon is contact-only: the shared IC identity contract must not require it.
    const kapsam = read("api/src/Services/Personel/PersonelCalisanKapsamService.php");
    const assertFn = kapsam.slice(kapsam.indexOf("function assertInternalIdentityComplete"));
    expect(assertFn).toContain("tc_kimlik_no");
    expect(assertFn).toContain("soyad");
    expect(assertFn).toContain("dogum_tarihi");
    expect(assertFn).not.toContain("Ic personel icin telefon zorunludur.");
    expect(phpCodeOnly(assertFn)).not.toMatch(/\$merged\['telefon'\]/);
  });

  it("never bypasses or weakens PersonelArchiveGate", () => {
    const gate = read(GATE_PATH);
    expect(gate).toContain("ARCHIVED_PERSONEL_READ_ONLY");
    expect(gate).toContain("assertBusinessWriteAllowed");

    const serviceCode = phpCodeOnly(read(SERVICE_PATH));
    // The reactivation owner must not call into the gate or force it open.
    expect(serviceCode).not.toContain("PersonelArchiveGate");
    expect(serviceCode).not.toContain("assertBusinessWriteAllowed");
    // The only aktif_durum write is the guarded PASIF -> AKTIF transition.
    expect(serviceCode).not.toContain("aktif_durum = 'PASIF' WHERE");
    expect(serviceCode).not.toMatch(/DELETE\s+FROM\s+`?surecler`?/i);

    // Archived records still cannot be reached through the generic update path.
    const controller = read(CONTROLLER_PATH);
    expect(controller).toContain("PersonelArchiveGate::assertBusinessWriteAllowed");
    expect(controller).toContain("assertAktifDurumNotChanged");
  });

  it("registers exactly one narrow route and gates it on arsiv.view + personeller.reaktif", () => {
    const router = read("api/src/Router.php");
    expect(router).toContain("yeniden-aktif");
    expect(router).toContain("PersonellerController::yenidenAktif");
    // One route only — no parallel bulk/alternate reactivation endpoints.
    expect(router.match(/yeniden-aktif/g)?.length).toBe(1);

    const controller = read(CONTROLLER_PATH);
    const action = controller.slice(
      controller.indexOf("public static function yenidenAktif("),
      controller.indexOf("public static function kaliciSubeDegisikligi(")
    );
    expect(action.length).toBeGreaterThan(0);
    // Both grants are mandatory together.
    expect(action).toContain("ArchiveAccessService::assertPasifAccess($user)");
    expect(action).toContain("RolePermissions::assert($user, 'personeller.reaktif')");
    // The reactivation route must not pre-block itself on the archive write gate.
    expect(action).not.toContain("assertBusinessWriteAllowed");
    // Idempotent retry contract.
    expect(action).toContain("OfflineMutationIdempotencyService::findCompletedReplay");
    expect(action).toContain("OfflineMutationIdempotencyService::claimInTransaction");
    expect(action).toContain("OfflineMutationIdempotencyService::completeInTransaction");
  });

  it("grants personeller.reaktif only to the personnel lifecycle owners", () => {
    const php = read("api/src/Auth/RolePermissions.php");
    expect(php).toContain("'personeller.reaktif'");

    for (const role of ["GENEL_YONETICI", "IK_SORUMLUSU", "IK_PERSONELI"] as const) {
      expect(hasRolePermission(role, "personeller.reaktif")).toBe(true);
    }
    for (const role of [
      "SUBE_YONETICISI",
      "BOLUM_YONETICISI",
      "MUHASEBE",
      "BIRIM_AMIRI",
      "SISTEM_YONETICISI",
      "PERSONEL"
    ] as const) {
      expect(hasRolePermission(role, "personeller.reaktif")).toBe(false);
    }
    // Mandatory pairing: the grant is useless without the archive visibility grant.
    for (const role of ["GENEL_YONETICI", "IK_SORUMLUSU"] as const) {
      expect(hasRolePermission(role, "arsiv.view")).toBe(true);
    }
  });

  it("ships no new UI, modal or parallel form for reactivation", () => {
    const ui = [
      "src/features/personeller/pages/PersonellerPage.tsx",
      "src/features/personeller/pages/PersonelDetayPage.tsx"
    ];
    for (const file of ui) {
      const source = read(file);
      expect(source).not.toContain("yeniden-aktif");
      expect(source).not.toContain("reaktif");
    }
  });
});
