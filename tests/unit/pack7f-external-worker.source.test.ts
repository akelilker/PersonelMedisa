import { describe, expect, it } from "vitest";
import { readFileSync } from "node:fs";
import { resolve } from "node:path";

function read(path: string) {
  return readFileSync(resolve(process.cwd(), path), "utf8");
}

describe("Pack7F external worker source locks", () => {
  it("owns schema compatibility and directory-only policy centrally", () => {
    const schema = read("api/src/Services/Personel/PersonelCalisanKapsamSchema.php");
    const service = read("api/src/Services/Personel/PersonelCalisanKapsamService.php");
    expect(schema).toContain("SCHEMA_NOT_READY");
    expect(schema).toContain("assertReadyForDisKaynakWrite");
    expect(service).toContain("PERSONEL_OPERASYON_KAPSAM_DISI");
    expect(service).toContain("sqlIcPersonelPredicate");
    expect(service).toContain("assertOperationalEligible");
    expect(service).toContain("DIS_KAYNAK_SGK_ISVEREN_YASAK");
  });

  it("keeps candidate filters and direct-action guards in operational owners", () => {
    const files = [
      "api/src/Controllers/BordroHazirlikController.php",
      "api/src/Services/BordroHazirlikPreflightService.php",
      "api/src/Services/MaasHesaplamaSnapshotService.php",
      "api/src/Services/SgkPrimGunuService.php",
      "api/src/Controllers/HaftalikKapanisController.php"
    ].map(read).join("\n");
    expect(files.match(/sqlIcPersonelPredicate/g)?.length ?? 0).toBeGreaterThanOrEqual(6);

    for (const path of [
      "api/src/Services/Qr/QrAttendanceEventService.php",
      "api/src/Controllers/PuantajController.php",
      "api/src/Controllers/BildirimlerController.php",
      "api/src/Controllers/SureclerController.php",
      "api/src/Controllers/FazlaCalismaOdemeTercihiController.php",
      "api/src/Controllers/SerbestZamanController.php",
      "api/src/Services/PersonelUcretService.php",
      "api/src/Controllers/MaasHesaplamaController.php"
    ]) {
      expect(read(path), path).toContain("assertOperationalEligible");
    }

    for (const path of [
      "api/src/Services/PersonelBordroDevirService.php",
      "api/src/Services/PuantajDonemReopenService.php",
      "api/src/Services/DonemKapanisPreflightService.php"
    ]) {
      expect(read(path), path).toContain("sqlIcPersonelPredicate");
    }
    for (const path of [
      "api/src/Services/BordroOnIzlemeService.php",
      "api/src/Services/MaasHesaplamaAdayService.php"
    ]) {
      expect(read(path), path).not.toContain("sqlIcPersonelPredicate");
    }
    expect(read("api/src/Services/SgkPrimGunuService.php")).not.toMatch(
      /listCanonicalResults[\s\S]*sqlIcPersonelPredicate/
    );
  });

  it("supports nullable identity, import default, list filter and UI badge", () => {
    const validator = read("api/src/Services/Personel/PersonelCanonicalValidator.php");
    const dryRun = read("api/src/Services/Personel/PersonelImportDryRunService.php");
    const page = read("src/features/personeller/pages/PersonellerPage.tsx");
    const controller = read("api/src/Controllers/PersonellerController.php");
    const detailPage = read("src/features/personeller/pages/PersonelDetayPage.tsx");
    const surecWorkspace = read("src/features/kayit/components/KayitSurecWorkspace.tsx");
    expect(validator).toContain("normalizeCreateIdentity");
    expect(validator).toContain("PersonelCalisanKapsamService::IC_PERSONEL");
    expect(dryRun).toContain("calisan_kapsami");
    expect(dryRun).toContain("$hasScopeColumn");
    expect(controller).toContain("calisan_kapsami = :calisan_kapsami");
    expect(page).toContain("Çalışan Kapsamı");
    expect(page).toContain("formatCalisanKapsamiLabel");
    expect(page).toContain("formatPersonelName");
    expect(detailPage).toContain("directoryOnly={isDirectoryOnly}");
    expect(surecWorkspace).toContain("isSelectedPersonelDirectoryOnly");
    expect(controller).toContain("SubeScope::");
  });
});
