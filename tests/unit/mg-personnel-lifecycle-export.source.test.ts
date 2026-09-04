import { readFileSync } from "node:fs";
import { resolve } from "node:path";
import { describe, expect, it } from "vitest";

const root = process.cwd();

function read(path: string): string {
  return readFileSync(resolve(root, path), "utf8");
}

describe("MG personnel lifecycle export closeout sources", () => {
  it("owns global branch selection against session.sube_list", () => {
    const manager = read("src/auth/auth-manager.ts");
    expect(manager).toContain("session.sube_list");
    expect(manager).toContain("GLOBAL_SCOPE_ROLES");
    expect(manager).not.toMatch(/ids\.length === 0[\s\S]*nextId !== null[\s\S]*return;/);
  });

  it("exports real XLSX via PersonelExportService with reconcile guard", () => {
    const exportSvc = read("api/src/Services/Personel/PersonelExportService.php");
    expect(exportSvc).toContain("SimpleXlsxWriter");
    expect(exportSvc).toContain("PERSONEL_EXPORT_RECONCILE_FAILED");
    expect(exportSvc).not.toContain("tc_kimlik_no");
    expect(exportSvc).toContain("calisma_lokasyonu_adi");
    expect(exportSvc).toContain("sube_adi");
  });

  it("blocks generic PUT organisation changes", () => {
    const orgSvc = read("api/src/Services/Personel/PersonelOrganizasyonDegisikligiService.php");
    expect(orgSvc).toContain("assertNotChangedViaGenericPut");
    expect(orgSvc).toContain("PERSONEL_ORGANIZASYON_CANONICAL_OWNER_REQUIRED");
  });

  it("registers lifecycle bulk dry-run route", () => {
    const router = read("api/src/Router.php");
    expect(router).toContain("/personeller/export.xlsx");
    expect(router).toContain("/personeller/lifecycle-bulk/dry-run");
    expect(router).toContain("/personeller/lifecycle-bulk/apply");
    expect(router).toContain("organizasyon-degisikligi");
  });

  it("rotates migration control plane to tip 084", () => {
    const preflight = read("api/src/Database/MigrationPreflightReport.php");
    expect(preflight).toContain("'084' => '084_gunluk_bildirim_tamamlama_header_summary.sql'");
    expect(preflight).toContain("EXPECTED_APPLIED_TIP = '083'");
    expect(preflight).toContain("personel_organizasyon_degisiklik_auditleri");
  });
});
