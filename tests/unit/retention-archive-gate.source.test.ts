import { readFileSync } from "node:fs";
import { resolve } from "node:path";
import { describe, expect, it } from "vitest";
import { hasRolePermission } from "../../src/lib/authorization/role-permissions";

const root = process.cwd();

describe("retention archive gate source contract", () => {
  it("gates PersonellerController list/detail for PASIF / arsiv.view", () => {
    const ctrl = readFileSync(
      resolve(root, "api/src/Controllers/PersonellerController.php"),
      "utf8"
    );
    expect(ctrl).toContain("PersonelArchiveGate::effectiveListAktiflik");
    expect(ctrl).toContain("PersonelArchiveGate::assertDetailAccess");
    expect(ctrl).toContain("PersonelArchiveGate::maybeWriteListAudit");
    expect(ctrl).toContain("PersonelArchiveGate::writeViewAuditIfPasif");
    expect(ctrl).toContain("arsiv_modu");
    expect(ctrl).toContain("retention_summary");
  });

  it("PersonelArchiveGate forces AKTIF without arsiv.view", () => {
    const gate = readFileSync(
      resolve(root, "api/src/Services/Retention/PersonelArchiveGate.php"),
      "utf8"
    );
    expect(gate).toContain("effectiveListAktiflik");
    expect(gate).toContain("return 'aktif'");
    expect(gate).toContain("ArchiveAccessService::assertPasifAccess");
  });

  it("PersonelBelgeler download audits PASIF with arsiv.download", () => {
    const belge = readFileSync(
      resolve(root, "api/src/Controllers/PersonelBelgelerController.php"),
      "utf8"
    );
    expect(belge).toContain("ARCHIVE_DOWNLOAD_REQUIRED");
    expect(belge).toContain("ArchiveAccessService::ACTION_DOWNLOAD");
    expect(belge).toContain("PersonelArchiveGate::effectiveListAktiflik");
    expect(belge).toContain("PersonelArchiveGate::assertBusinessWriteAllowed");
    expect(belge).toContain("ARCHIVE_AUDIT_UNAVAILABLE");
  });

  it("PASIF write gate and lifecycle manifests on ISTEN_AYRILMA", () => {
    const gate = readFileSync(
      resolve(root, "api/src/Services/Retention/PersonelArchiveGate.php"),
      "utf8"
    );
    expect(gate).toContain("assertBusinessWriteAllowed");
    expect(gate).toContain("ARCHIVED_PERSONEL_READ_ONLY");

    const surec = readFileSync(
      resolve(root, "api/src/Controllers/SureclerController.php"),
      "utf8"
    );
    expect(surec).toContain("createPersonelLifecycleManifests");
    expect(surec).toContain("PersonelArchiveGate::assertBusinessWriteAllowed");

    const puantaj = readFileSync(
      resolve(root, "api/src/Controllers/PuantajController.php"),
      "utf8"
    );
    expect(puantaj).toContain("createPuantajPeriodManifests");

    const haftalik = readFileSync(
      resolve(root, "api/src/Controllers/HaftalikKapanisController.php"),
      "utf8"
    );
    expect(haftalik).toContain("createHaftalikPeriodManifests");

    const personeller = readFileSync(
      resolve(root, "api/src/Controllers/PersonellerController.php"),
      "utf8"
    );
    expect(personeller).toContain("PersonelArchiveGate::assertBusinessWriteAllowed");

    const zimmet = readFileSync(
      resolve(root, "api/src/Controllers/ZimmetlerController.php"),
      "utf8"
    );
    expect(zimmet).toContain("PersonelArchiveGate::assertBusinessWriteAllowed");

    const ucret = readFileSync(
      resolve(root, "api/src/Controllers/PersonelUcretController.php"),
      "utf8"
    );
    expect(ucret).toContain("PersonelArchiveGate::assertBusinessWriteAllowed");
  });

  it("registers arsiv / legal-hold / retention routes", () => {
    const router = readFileSync(resolve(root, "api/src/Router.php"), "utf8");
    expect(router).toContain("/arsiv/personeller");
    expect(router).toContain("/legal-holdlar");
    expect(router).toContain("/retention/eligibility");
    expect(router).toContain("/retention/imha-talepleri");
    expect(router).toContain("ArsivController");
    expect(router).toContain("LegalHoldController");
    expect(router).toContain("RetentionController");
  });

  it("role permission matrix additions", () => {
    const php = readFileSync(resolve(root, "api/src/Auth/RolePermissions.php"), "utf8");
    for (const key of [
      "arsiv.view",
      "arsiv.download",
      "arsiv.audit.view",
      "retention.view",
      "legal_hold.manage",
      "retention.destruction.request",
      "retention.destruction.approve",
      "retention.destruction.view"
    ]) {
      expect(php).toContain(key);
    }
    expect(php).toContain("'SISTEM_YONETICISI'");
    expect(php).toContain("'GENEL_YONETICI'");
    expect(php).not.toContain("'IDARI_ISLER' =>");
    expect(hasRolePermission("GENEL_YONETICI", "arsiv.view")).toBe(true);
    expect(hasRolePermission("GENEL_YONETICI", "retention.destruction.approve")).toBe(true);
    expect(hasRolePermission("SISTEM_YONETICISI", "arsiv.audit.view")).toBe(true);
    expect(hasRolePermission("SISTEM_YONETICISI", "retention.destruction.view")).toBe(true);
    expect(hasRolePermission("SISTEM_YONETICISI", "legal_hold.manage")).toBe(false);
    expect(hasRolePermission("MUHASEBE", "arsiv.view")).toBe(false);
  });

  it("frontend shows archive option and Medisa policy banner", () => {
    const list = readFileSync(
      resolve(root, "src/features/personeller/pages/PersonellerPage.tsx"),
      "utf8"
    );
    expect(list).toContain('hasPermission("arsiv.view")');
    expect(list).toContain("Arşiv — Medisa saklama politikası");
    expect(list).toContain(">Arşiv<");

    const detail = readFileSync(
      resolve(root, "src/features/personeller/pages/PersonelDetayPage.tsx"),
      "utf8"
    );
    expect(detail).toContain("En erken imha değerlendirme tarihi");
    expect(detail).toContain("Legal hold aktif");
    expect(detail).not.toContain("otomatik silinecek");

    const panel = readFileSync(
      resolve(root, "src/features/yonetim/components/SaklamaLegalHoldPanel.tsx"),
      "utf8"
    );
    expect(panel).toContain("Medisa saklama politikası");
    expect(panel).toContain("Otomatik silme yoktur");
    expect(panel).toMatch(/minimum 10 takvim yılı/);
    expect(panel).not.toMatch(/\botomatik silinecek\b/i);
    expect(panel).not.toMatch(/\bkanunen\b/i);
  });
});
